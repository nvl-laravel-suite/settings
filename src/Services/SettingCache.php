<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use UnexpectedValueException;

/**
 * Caches setting records as primitive attributes and invalidates them after commits.
 *
 * @internal
 */
final class SettingCache
{
    /** @var array<string, Definition>|null */
    private ?array $definitionSet = null;

    private string $definitionFingerprint = '';

    /**
     * Create the settings record cache.
     */
    public function __construct(
        private readonly Factory $cache,
        private readonly DatabaseManager $database,
        private readonly DefinitionRepository $definitions,
        private readonly OwnershipBoundary $boundary,
    ) {}

    /**
     * Return all persisted setting records through the configured cache.
     *
     * @return Collection<int, Setting>
     */
    public function records(): Collection
    {
        if (! $this->usesCache()) {
            return $this->fetch();
        }

        return collect($this->payload($this->entryIdentity()))->map($this->hydrate(...))->values();
    }

    /** Return one record without hydrating the rest of the cached catalog. */
    public function record(string $key): ?Setting
    {
        $definition = $this->definitions->get($key);
        if (! $this->usesCache()) {
            return $this->boundary->query(SettingModelRegistry::query(), 'settings.values')
                ->where('namespace', $definition->namespace)
                ->where('scope', $definition->scope)
                ->where('key', $definition->key)
                ->first();
        }

        $payload = $this->payload($this->recordIdentity($key), $definition);

        return isset($payload[0]) ? $this->hydrate($payload[0]) : null;
    }

    /** Return the generation-qualified cache identity for one defined setting. */
    public function recordIdentity(string $key): SettingCacheIdentity
    {
        $this->definitions->get($key);

        return $this->entryIdentity($key);
    }

    /** Capture one atomic cache generation before any database fill begins. */
    private function entryIdentity(?string $key = null): SettingCacheIdentity
    {
        $identity = $this->identity();
        $store = $this->cache->store($identity->store);
        $generationKey = $identity->key.':generation';
        $generation = $store->get($generationKey);
        if (! is_string($generation) || $generation === '') {
            $store->add($generationKey, (string) Str::uuid(), $this->ttl());
            $generation = $store->get($generationKey);
        }
        if (! is_string($generation) || $generation === '') {
            $generation = (string) Str::uuid();
        }

        return new SettingCacheIdentity($identity->store, $identity->key.':'.$generation.':'.($key === null ? 'records' : hash('sha256', $key)));
    }

    /** Return a finite cache lifetime, including for obsolete generations. */
    private function ttl(): int
    {
        $ttl = config('nvl-settings.cache.ttl', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    /** Use shared committed records only outside a transaction on this source. */
    private function usesCache(): bool
    {
        return (bool) config('nvl-settings.cache.enabled', true)
            && $this->database->connection((SettingModelRegistry::newInstance())->getConnectionName())->transactionLevel() === 0;
    }

    /** @return list<array<string, bool|float|int|string|null>> */
    private function payload(SettingCacheIdentity $identity, ?Definition $definition = null): array
    {
        $store = $this->cache->store($identity->store);
        $key = $identity->key;
        $payload = $store->get($key);

        if (! $this->isValidPayload($payload) || ($definition !== null && ! $this->matchesDefinition($payload, $definition))) {
            if ($payload !== null) {
                $store->forget($key);
            }

            $payload = $store->remember(
                $key,
                $this->ttl(),
                fn (): array => $this->fetchPayload($definition),
            );
        }

        if (! $this->isValidPayload($payload) || ($definition !== null && ! $this->matchesDefinition($payload, $definition))) {
            throw new UnexpectedValueException('The settings cache could not supply a valid primitive record payload.');
        }

        return $payload;
    }

    /** @param array<string, bool|float|int|string|null> $attributes */
    private function hydrate(array $attributes): Setting
    {
        $setting = SettingModelRegistry::newInstance();
        $setting->setRawAttributes($attributes, true);
        $setting->exists = true;
        if ($setting->getRawOriginal('owner_scope_key') !== null) {
            $this->boundary->assertRecord($setting, 'settings.values');
        }

        return $setting;
    }

    /**
     * Forget the cached records immediately.
     */
    public function flush(): void
    {
        $this->flushIdentity($this->identity());
    }

    /** Return the immutable identity for the current admitted partition. */
    public function identity(): SettingCacheIdentity
    {
        $model = SettingModelRegistry::newInstance();
        $attributes = $this->boundary->attributes('settings.values');
        $ownership = isset($attributes['owner_scope_key'])
            ? $this->boundary->key('settings.values', 'records')
            : 'disabled';

        return $this->makeIdentity($model, $ownership, $this->definitionHash());
    }

    /** Derive a stable identity directly from a persisted row. */
    public function identityFor(Setting $setting): SettingCacheIdentity
    {
        $scope = $setting->getRawOriginal('owner_scope_key');
        if ($scope !== null) {
            if ($scope !== ($this->boundary->attributes('settings.values')['owner_scope_key'] ?? null)) {
                throw new OwnershipViolation('Cache invalidation must capture the persisted record partition.');
            }
        }

        return $this->makeIdentity(
            $setting,
            $scope !== null ? $this->boundary->key('settings.values', 'records') : 'disabled',
            $this->definitionHash(),
        );
    }

    /** Forget one previously captured cache identity. */
    public function flushIdentity(SettingCacheIdentity $identity): void
    {
        $this->cache->store($identity->store)->forget($identity->key.':generation');
    }

    /**
     * Forget the cached records after the active outer transaction commits.
     */
    public function flushAfterCommit(): void
    {
        $connection = $this->database->connection((SettingModelRegistry::newInstance())->getConnectionName());
        $identity = $this->identity();

        if ($connection->transactionLevel() === 0) {
            $this->flushIdentity($identity);

            return;
        }

        $connection->afterCommit(fn () => $this->flushIdentity($identity));
    }

    /**
     * Fetch settings from storage without hiding database failures.
     *
     * @return Collection<int, Setting>
     */
    private function fetch(): Collection
    {
        return $this->boundary->query(SettingModelRegistry::query(), 'settings.values')->get();
    }

    /**
     * Fetch primitive cache payloads from storage.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchPayload(?Definition $definition = null): array
    {
        $query = $this->boundary->query(SettingModelRegistry::query(), 'settings.values');
        if ($definition !== null) {
            $query->where('namespace', $definition->namespace)
                ->where('scope', $definition->scope)
                ->where('key', $definition->key)
                ->limit(1);
        }

        return array_values($query->get()
            ->map(static fn (Setting $setting): array => $setting->getAttributes())
            ->values()
            ->all());
    }

    /**
     * Determine whether a cached value is a safe primitive setting payload.
     *
     * @phpstan-assert-if-true list<array<string, bool|float|int|string|null>> $payload
     */
    private function isValidPayload(mixed $payload): bool
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            return false;
        }

        $required = [
            'id',
            'namespace',
            'scope',
            'key',
            'type',
            'value',
            'has_override',
            'fallback',
            'metadata',
            'definition_hash',
            'revision',
            'valid_from',
            'valid_until',
            'synced_at',
            'orphaned_at',
            'created_at',
            'updated_at',
        ];
        $required = [...$required, ...array_keys($this->boundary->attributes('settings.values'))];

        foreach ($payload as $attributes) {
            if (! is_array($attributes)
                || array_diff($required, array_keys($attributes)) !== []) {
                return false;
            }

            foreach ($attributes as $attribute => $value) {
                if (! is_string($attribute)
                    || (! is_scalar($value) && $value !== null)) {
                    return false;
                }
            }

            if (! is_string($attributes['id'])
                || ! is_string($attributes['namespace'])
                || ! is_string($attributes['scope'])
                || ! is_string($attributes['key'])
                || ! is_string($attributes['type'])
                || ! is_string($attributes['definition_hash'])
                || (! is_string($attributes['value']) && $attributes['value'] !== null)
                || (! is_string($attributes['fallback']) && $attributes['fallback'] !== null)
                || (! is_string($attributes['metadata']) && $attributes['metadata'] !== null)
                || ! in_array(
                    $attributes['has_override'],
                    [false, true, 0, 1, '0', '1'],
                    true,
                )
                || (is_int($attributes['revision']) && $attributes['revision'] < 1)
                || (! is_int($attributes['revision'])
                    && (! is_string($attributes['revision'])
                        || preg_match('/^[1-9]\d*$/', $attributes['revision']) !== 1))) {
                return false;
            }

            foreach ([
                'valid_from',
                'valid_until',
                'synced_at',
                'orphaned_at',
                'created_at',
                'updated_at',
            ] as $timestamp) {
                if (! is_string($attributes[$timestamp])
                    && $attributes[$timestamp] !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<array<string, bool|float|int|string|null>> $payload */
    private function matchesDefinition(array $payload, Definition $definition): bool
    {
        return $payload === [] || (count($payload) === 1
            && $payload[0]['namespace'] === $definition->namespace
            && $payload[0]['scope'] === $definition->scope
            && $payload[0]['key'] === $definition->key);
    }

    /** Rehash definitions only when their immutable object set changes. */
    private function definitionHash(): string
    {
        $definitions = $this->definitions->all();
        if ($this->definitionSet !== $definitions) {
            $hashes = array_map(static fn (Definition $definition): string => $definition->hash(), $definitions);
            sort($hashes);
            $this->definitionFingerprint = hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
            $this->definitionSet = $definitions;
        }

        return $this->definitionFingerprint;
    }

    /**
     * Return the optional validated cache store.
     */
    private function store(): ?string
    {
        $store = config('nvl-settings.cache.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * Return the validated settings cache key.
     */
    private function key(): string
    {
        $key = config('nvl-settings.cache.key', 'nvl:settings:v2');

        return is_string($key) && $key !== '' ? $key : 'nvl:settings:v2';
    }

    /**
     * Build one deterministic cache key without consulting ambient context.
     *
     * @param  string  $definitionHash  Fingerprint of the current immutable definition set.
     */
    private function makeIdentity(Setting $setting, string $ownership, string $definitionHash): SettingCacheIdentity
    {
        $connection = $setting->getConnection();

        return new SettingCacheIdentity($this->store(), implode(':', [
            $this->key(),
            hash('sha256', json_encode([
                $setting::class,
                $connection->getName(),
                $connection->getDriverName(),
                $connection->getConfig('host'),
                $connection->getConfig('port'),
                $connection->getConfig('username'),
                $connection->getDatabaseName(),
                $connection->getConfig('search_path'),
                $connection->getConfig('schema'),
                $connection->getTablePrefix().$setting->getTable(),
                $ownership,
                $definitionHash,
            ], JSON_THROW_ON_ERROR)),
        ]));
    }
}
