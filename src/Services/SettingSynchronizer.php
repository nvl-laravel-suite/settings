<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Settings\Data\SettingSyncResultData;
use Nvl\Settings\Enums\SettingPruneStrategy;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;

/**
 * Aligns persisted settings with source definitions without replacing live overrides.
 *
 * This service owns the transaction because definition alignment, override validation,
 * pruning, and cache invalidation form one reusable atomic write boundary.
 *
 * @internal
 */
final readonly class SettingSynchronizer
{
    /**
     * Create the stable settings synchronization boundary.
     */
    public function __construct(
        private DatabaseManager $database,
        private SettingValueValidator $values,
        private SettingCache $cache,
    ) {}

    /**
     * Report the current synchronization delta without mutating storage.
     *
     * @param  array<string, Definition>  $definitions
     */
    public function preview(
        array $definitions,
        ?string $provider,
        SettingPruneStrategy $prune,
    ): SettingSyncResultData {
        $existing = $this->existingQuery($provider)
            ->get()
            ->keyBy(static fn (Setting $setting): string => $setting->fullKey());
        $orphanCount = $prune === SettingPruneStrategy::Ignore
            ? 0
            : $existing->keys()->diff(array_keys($definitions))->count();

        return new SettingSyncResultData(
            synchronized: count($definitions),
            orphans: $orphanCount,
            failures: $this->storedValueFailures($definitions, $existing),
        );
    }

    /**
     * Synchronize definitions and prune removed records atomically.
     *
     * @param  array<string, Definition>  $definitions
     *
     * @throws ValidationException
     */
    public function synchronize(
        array $definitions,
        ?string $provider,
        SettingPruneStrategy $prune,
        bool $respectDatabaseValues,
    ): SettingSyncResultData {
        $connection = $this->database->connection((new Setting)->getConnectionName());

        return $connection->transaction(function () use (
            $definitions,
            $provider,
            $prune,
            $respectDatabaseValues,
        ): SettingSyncResultData {
            $now = now();
            $existing = $this->existingQuery($provider)
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (Setting $setting): string => $setting->fullKey());

            foreach ($definitions as $fullKey => $definition) {
                $setting = $existing->get($fullKey);

                if (! $setting instanceof Setting) {
                    Setting::query()->insertOrIgnore([
                        'id' => (string) Str::uuid(),
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
                $clearOverride = ! $respectDatabaseValues
                    && $setting->getRawOriginal('fallback') !== $serializedFallback
                    && $setting->has_override;
                $definitionHash = $definition->hash();
                $definitionChanged = $setting->definition_hash !== $definitionHash;
                $restored = $setting->orphaned_at !== null;
                $revision = $setting->revision;

                if ($definitionChanged || $clearOverride || $restored) {
                    $revision++;
                }

                Setting::query()->whereKey($setting->getKey())->update([
                    'type' => $definition->type->value,
                    'value' => $clearOverride ? null : $canonicalValue,
                    'has_override' => $clearOverride ? false : $setting->has_override,
                    'fallback' => $serializedFallback,
                    'metadata' => json_encode($definition->metadata, JSON_THROW_ON_ERROR),
                    'definition_hash' => $definitionHash,
                    'revision' => $revision,
                    'valid_from' => $clearOverride ? null : $setting->getRawOriginal('valid_from'),
                    'valid_until' => $clearOverride ? null : $setting->getRawOriginal('valid_until'),
                    'synced_at' => $now,
                    'orphaned_at' => null,
                    'updated_at' => $now,
                ]);
            }

            $orphaned = $prune === SettingPruneStrategy::Ignore
                ? $existing->take(0)
                : $existing->reject(
                    static fn (Setting $setting): bool => isset($definitions[$setting->fullKey()]),
                );
            $this->applyPrune($orphaned, $prune, $now);
            $this->cache->flushAfterCommit();

            return new SettingSyncResultData(
                synchronized: count($definitions),
                orphans: $orphaned->count(),
            );
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
            $ids = $orphaned
                ->map(static fn (Setting $setting): string => $setting->id)
                ->values()
                ->all();
            Setting::query()->whereKey($ids)->delete();

            return;
        }

        foreach ($orphaned as $setting) {
            if ($setting->orphaned_at !== null) {
                continue;
            }

            Setting::query()->whereKey($setting->getKey())->update([
                'orphaned_at' => $now,
                'revision' => $setting->revision + 1,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Build the provider-bounded existing-record query.
     *
     * @return Builder<Setting>
     */
    private function existingQuery(?string $provider): Builder
    {
        return Setting::query()->when(
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
        return Setting::query()->where([
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
