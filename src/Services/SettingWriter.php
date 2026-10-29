<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Enums\SettingPruneStrategy;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\SettingAuditContext;
use Nvl\Settings\Support\SettingRecord;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Owns authorized setting mutations and definition reconciliation.
 *
 * This service owns the transaction because definition alignment, override validation,
 * pruning, and cache invalidation form one reusable atomic write boundary.
 *
 * @api
 *
 * @phpstan-import-type Resolved from SettingRecord
 */
final readonly class SettingWriter
{
    /**
     * Create the stable settings synchronization boundary.
     */
    public function __construct(
        private DatabaseManager $database,
        private SettingValueValidator $values,
        private SettingCache $cache,
        private OwnershipBoundary $boundary,
        private SettingCreator $creator,
        private DefinitionRepository $definitions,
        private SettingsAuthorization $authorization,
        private SettingsAuditContextProvider $auditContext,
        private OwnershipContext $ownershipContext,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Persist one typed override with optimistic concurrency. Existing rows require an exact revision.
     * Options accept only valid_from and valid_until; omission preserves an existing window and null clears it.
     *
     * @param  array{valid_from?: DateTimeInterface|string|null, valid_until?: DateTimeInterface|string|null}  $options
     * @return Resolved
     */
    public function set(
        string $key,
        mixed $value,
        Authenticatable|SystemActor $actor,
        ?int $expectedRevision = null,
        array $options = [],
    ): array {
        $actor = $this->resolveActor($actor);
        $this->authorization->authorize($actor, SettingAbility::Set, $key);
        if (array_diff(array_keys($options), ['valid_from', 'valid_until']) !== []) {
            throw new InvalidArgumentException('Unknown setting mutation option.');
        }
        if ($expectedRevision !== null && $expectedRevision < 0) {
            throw new InvalidArgumentException('Expected revision must be nonnegative.');
        }
        $definition = $this->definitions->get($key);
        $this->assertOverrideAllowed($definition);
        $this->values->validate($definition, $value);
        $providedFrom = array_key_exists('valid_from', $options)
            ? $this->parseValidity('valid_from', $options['valid_from']) : null;
        $providedUntil = array_key_exists('valid_until', $options)
            ? $this->parseValidity('valid_until', $options['valid_until']) : null;
        if ($definition->overrides !== null && ($providedFrom !== null || $providedUntil !== null)) {
            throw ValidationException::withMessages(['valid_from' => ['Configuration overrides cannot use scheduled validity windows.']]);
        }

        $connection = $this->database->connection((SettingModelRegistry::newInstance())->getConnectionName());
        $setting = $connection->transaction(function () use (
            $actor, $connection, $definition, $expectedRevision, $key, $options, $providedFrom, $providedUntil, $value,
        ): Setting {
            $setting = $this->identityQuery($definition)->lockForUpdate()->first();
            $created = false;
            $validFrom = array_key_exists('valid_from', $options) ? $providedFrom : $setting?->valid_from;
            $validUntil = array_key_exists('valid_until', $options) ? $providedUntil : $setting?->valid_until;
            if ($definition->overrides !== null) {
                $validFrom = null;
                $validUntil = null;
            }
            $this->validateWindow($validFrom, $validUntil);

            if (! $setting instanceof Setting) {
                if ($expectedRevision !== null && $expectedRevision !== 0) {
                    throw StaleSettingVersionException::forKey($key);
                }
                $now = now();
                $inserted = $this->creator->insert([
                    'id' => (string) Str::uuid(),
                    ...$this->boundary->attributes('settings.values'),
                    'namespace' => $definition->namespace,
                    'scope' => $definition->scope,
                    'key' => $definition->key,
                    'type' => $definition->type->value,
                    'value' => $definition->type->serialize($value),
                    'has_override' => true,
                    'fallback' => $definition->type->serialize($definition->default),
                    'metadata' => json_encode($definition->metadata, JSON_THROW_ON_ERROR),
                    'definition_hash' => $definition->hash(),
                    'revision' => 1,
                    'valid_from' => $validFrom?->format('Y-m-d H:i:s'),
                    'valid_until' => $validUntil?->format('Y-m-d H:i:s'),
                    'synced_at' => $now,
                    'orphaned_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $setting = $this->identityQuery($definition)->lockForUpdate()->firstOrFail();
                if ($inserted === 0 && $expectedRevision === 0) {
                    throw StaleSettingVersionException::forKey($key);
                }
                $created = $inserted === 1;
            }
            if (! $created && ($expectedRevision === null || $setting->revision !== $expectedRevision)) {
                throw StaleSettingVersionException::forKey($key);
            }
            if (! $created) {
                $setting->fill([
                    'type' => $definition->type,
                    'value' => $value,
                    'has_override' => true,
                    'fallback' => $definition->default,
                    'metadata' => $definition->metadata,
                    'definition_hash' => $definition->hash(),
                    'orphaned_at' => null,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                ]);
                if ($setting->isDirty()) {
                    $setting->synced_at = now();
                    $setting->save();
                    $setting->refresh();
                }
            }
            if ($created || $setting->wasChanged()) {
                $this->cache->flushAfterCommit();
                $this->publishChange($setting, 'set', $actor, $connection);
            }

            return $setting;
        });

        return SettingRecord::resolved($definition, $setting);
    }

    /** Clear an override only at its exact revision and return its resolved fallback metadata.
     * @return Resolved
     */
    public function reset(string $key, Authenticatable|SystemActor $actor, int $expectedRevision): array
    {
        $actor = $this->resolveActor($actor);
        $this->authorization->authorize($actor, SettingAbility::Reset, $key);
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected revision must be positive.');
        }
        $definition = $this->definitions->get($key);
        $connection = $this->database->connection((SettingModelRegistry::newInstance())->getConnectionName());
        $setting = $connection->transaction(function () use ($actor, $connection, $definition, $expectedRevision, $key): Setting {
            $setting = $this->identityQuery($definition)->lockForUpdate()->firstOrFail();
            if ($setting->revision !== $expectedRevision) {
                throw StaleSettingVersionException::forKey($key);
            }
            if (! $setting->isCustomised()) {
                return $setting;
            }
            $setting->value = null;
            $setting->has_override = false;
            $setting->valid_from = null;
            $setting->valid_until = null;
            $setting->save();
            $setting->refresh();
            $this->cache->flushAfterCommit();
            $this->publishChange($setting, 'reset', $actor, $connection);

            return $setting;
        });

        return SettingRecord::resolved($definition, $setting);
    }

    /**
     * Reconcile source definitions as an explicit deployment operation, preserving valid overrides by default.
     * No arbitrary value, actor, or ownership input is accepted by this infrastructure operation.
     *
     * @return array{synchronized: int, orphans: int, failures: list<string>}
     */
    public function sync(
        ?string $provider = null,
        SettingPruneStrategy $prune = SettingPruneStrategy::Orphan,
        bool $dryRun = false,
    ): array {
        if ($provider !== null && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $provider) !== 1) {
            throw new InvalidArgumentException('The provider namespace is invalid.');
        }
        $this->definitions->refresh();
        $definitions = array_filter($this->definitions->all(),
            static fn (Definition $definition): bool => $provider === null || $definition->namespace === $provider);

        return $dryRun
            ? $this->preview($definitions, $provider, $prune)
            : $this->synchronize($definitions, $provider, $prune);
    }

    /**
     * Report the current synchronization delta without mutating storage.
     *
     * @param  array<string, Definition>  $definitions
     * @return array{synchronized: int, orphans: int, failures: list<string>}
     */
    private function preview(
        array $definitions,
        ?string $provider,
        SettingPruneStrategy $prune,
    ): array {
        $existing = $this->existingQuery($provider)
            ->get()
            ->keyBy(static fn (Setting $setting): string => $setting->fullKey());
        $orphanCount = $prune === SettingPruneStrategy::Ignore
            ? 0
            : $existing->keys()->diff(array_keys($definitions))->count();

        return ['synchronized' => count($definitions), 'orphans' => $orphanCount, 'failures' => $this->storedValueFailures($definitions, $existing)];
    }

    /**
     * Synchronize definitions and prune removed records atomically.
     *
     * @param  array<string, Definition>  $definitions
     * @return array{synchronized: int, orphans: int, failures: list<string>}
     *
     * @throws ValidationException
     */
    private function synchronize(
        array $definitions,
        ?string $provider,
        SettingPruneStrategy $prune,
    ): array {
        $connection = $this->database->connection((SettingModelRegistry::newInstance())->getConnectionName());

        return $connection->transaction(function () use (
            $definitions,
            $provider,
            $prune,
        ): array {
            $now = now();
            $existing = $this->existingQuery($provider)
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (Setting $setting): string => $setting->fullKey());

            foreach ($definitions as $fullKey => $definition) {
                $setting = $existing->get($fullKey);

                if (! $setting instanceof Setting) {
                    $this->creator->insert([
                        'id' => (string) Str::uuid(),
                        ...$this->boundary->attributes('settings.values'),
                        'namespace' => $definition->namespace,
                        'scope' => $definition->scope,
                        'key' => $definition->key,
                        'type' => $definition->type->value,
                        'value' => null,
                        'has_override' => false,
                        'fallback' => $definition->type->serialize($definition->default),
                        'metadata' => json_encode($definition->metadata, JSON_THROW_ON_ERROR),
                        'definition_hash' => $definition->hash(),
                        'revision' => 1,
                        'valid_from' => null,
                        'valid_until' => null,
                        'synced_at' => $now,
                        'orphaned_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $setting = $this->identityQuery($definition)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $rawValue = $setting->getRawOriginal('value');

                if (! is_string($rawValue) && $rawValue !== null) {
                    throw ValidationException::withMessages([
                        'value' => ["Stored value for [{$fullKey}] must be a string or null."],
                    ]);
                }

                $canonicalValue = $rawValue;

                if ($setting->has_override) {
                    try {
                        $canonicalValue = $this->values->validateStored(
                            $definition,
                            $rawValue,
                        );
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages([
                            'value' => [
                                "Stored override for [{$fullKey}] is invalid for "
                                ."[{$definition->type->value}]: "
                                .$this->firstValidationError($exception),
                            ],
                        ]);
                    }
                }

                $serializedFallback = $definition->type->serialize($definition->default);
                $definitionHash = $definition->hash();
                $definitionChanged = $setting->definition_hash !== $definitionHash;
                $restored = $setting->orphaned_at !== null;
                $revision = $setting->revision;

                if ($definitionChanged || $restored) {
                    $revision++;
                }

                $setting->setRawAttributes([...$setting->getAttributes(), ...[
                    'type' => $definition->type->value,
                    'value' => $canonicalValue,
                    'has_override' => $setting->has_override,
                    'fallback' => $serializedFallback,
                    'metadata' => json_encode($definition->metadata, JSON_THROW_ON_ERROR),
                    'definition_hash' => $definitionHash,
                    'revision' => $revision,
                    'valid_from' => $setting->getRawOriginal('valid_from'),
                    'valid_until' => $setting->getRawOriginal('valid_until'),
                    'synced_at' => $now,
                    'orphaned_at' => null,
                    'updated_at' => $now,
                ]]);
                $setting->saveQuietly();
            }

            $orphaned = $prune === SettingPruneStrategy::Ignore
                ? $existing->take(0)
                : $existing->reject(
                    static fn (Setting $setting): bool => isset($definitions[$setting->fullKey()]),
                );
            $this->applyPrune($orphaned, $prune, $now);
            $this->cache->flushAfterCommit();

            return ['synchronized' => count($definitions), 'orphans' => $orphaned->count(), 'failures' => []];
        });
    }

    /**
     * Return failures for stored overrides against current definitions.
     *
     * @param  array<string, Definition>  $definitions
     * @param  Collection<string, Setting>  $existing
     * @return list<string>
     */
    private function storedValueFailures(
        array $definitions,
        Collection $existing,
    ): array {
        $failures = [];

        foreach ($definitions as $fullKey => $definition) {
            $setting = $existing->get($fullKey);

            if (! $setting instanceof Setting || ! $setting->has_override) {
                continue;
            }

            $rawValue = $setting->getRawOriginal('value');

            try {
                if (! is_string($rawValue) && $rawValue !== null) {
                    throw ValidationException::withMessages([
                        'value' => ['Stored values must be strings or null.'],
                    ]);
                }

                $this->values->validateStored($definition, $rawValue);
            } catch (ValidationException $exception) {
                $failures[] = "Stored override for [{$fullKey}] is invalid for "
                    ."[{$definition->type->value}]: "
                    .$this->firstValidationError($exception);
            }
        }

        return $failures;
    }

    /**
     * Apply the configured behavior to definitions removed from source.
     *
     * @param  Collection<string, Setting>  $orphaned
     */
    private function applyPrune(
        Collection $orphaned,
        SettingPruneStrategy $prune,
        CarbonInterface $now,
    ): void {
        if ($prune === SettingPruneStrategy::Ignore || $orphaned->isEmpty()) {
            return;
        }

        if ($prune === SettingPruneStrategy::Delete) {
            foreach ($orphaned as $setting) {
                $setting->delete();
            }

            return;
        }

        foreach ($orphaned as $setting) {
            if ($setting->orphaned_at !== null) {
                continue;
            }

            $setting->forceFill([
                'orphaned_at' => $now,
                'revision' => $setting->revision + 1,
                'updated_at' => $now,
            ])->save();
        }
    }

    private function resolveActor(Authenticatable|SystemActor $actor): Authenticatable|SystemActor
    {
        if ($actor instanceof SystemActor) {
            return $actor;
        }
        $id = $actor->getAuthIdentifier();
        if ((! is_string($id) && ! is_int($id)) || $id === ''
            || ($actor instanceof Model && (! $actor->exists || $actor->isDirty($actor->getAuthIdentifierName())))) {
            throw new AuthorizationException('Settings mutations require a persisted actor.');
        }

        if (! $actor instanceof Model) {
            return $actor;
        }
        $canonical = new ($actor::class);
        $defaultConnection = $this->database->getDefaultConnection();
        if ($canonical->getTable() !== $actor->getTable()
            || $canonical->getKeyName() !== $actor->getKeyName()
            || ($canonical->getConnectionName() ?? $defaultConnection) !== ($actor->getConnectionName() ?? $defaultConnection)
            || $actor->getRawOriginal($actor->getKeyName()) !== $actor->getKey()) {
            throw new AuthorizationException('Settings actor storage cannot be substituted.');
        }
        $persisted = $canonical->newQuery()->whereKey($actor->getKey())->first();
        if (! $persisted instanceof Authenticatable || $persisted->getAuthIdentifier() !== $id) {
            throw new AuthorizationException('Settings mutations require a persisted actor.');
        }

        return $persisted;
    }

    private function assertOverrideAllowed(Definition $definition): void
    {
        $ownership = $this->boundary->attributes('settings.values');
        if (($ownership['owner_scope_key'] ?? 'central') !== 'central' && ! $definition->tenantOverride) {
            throw new AuthorizationException('This setting does not permit owner overrides.');
        }
    }

    private function parseValidity(string $attribute, DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            $parsed = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : SettingType::DateTime->deserialize($value);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$attribute => [$exception->getMessage()]]);
        }
        if (! $parsed instanceof CarbonImmutable || $parsed->format('u') !== '000000') {
            throw ValidationException::withMessages([$attribute => ['Validity timestamps require whole-second precision.']]);
        }

        return $parsed->setTimezone('UTC');
    }

    private function validateWindow(?CarbonInterface $validFrom, ?CarbonInterface $validUntil): void
    {
        if ($validFrom !== null && $validUntil !== null && ! $validUntil->isAfter($validFrom)) {
            throw ValidationException::withMessages(['valid_until' => ['The validity end must be after the validity start.']]);
        }
    }

    private function publishChange(Setting $setting, string $operation, Authenticatable|SystemActor $actor, Connection $connection): void
    {
        $this->domainEvents->dispatch(new SettingChanged(
            $setting->id,
            $setting->fullKey(),
            $setting->revision,
            $operation,
            SettingAuditContext::fromActor($actor, $this->auditContext->metadata()),
            OwnershipJobEnvelope::capture($this->ownershipContext),
        ), $connection);
    }

    /**
     * Build the provider-bounded existing-record query.
     *
     * @return Builder<Setting>
     */
    private function existingQuery(?string $provider): Builder
    {
        return $this->boundary->query(SettingModelRegistry::query(), 'settings.values')->when(
            $provider !== null,
            static fn (Builder $query): Builder => $query->where('namespace', $provider),
        );
    }

    /**
     * Build the exact identity query for one definition.
     *
     * @return Builder<Setting>
     */
    private function identityQuery(Definition $definition): Builder
    {
        return $this->boundary->query(SettingModelRegistry::query(), 'settings.values')->where([
            'namespace' => $definition->namespace,
            'scope' => $definition->scope,
            'key' => $definition->key,
        ]);
    }

    /**
     * Return the first string validation error.
     */
    private function firstValidationError(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message)) {
                    return $message;
                }
            }
        }

        return $exception->getMessage();
    }
}
