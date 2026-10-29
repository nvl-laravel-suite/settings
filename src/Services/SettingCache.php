<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Caches setting records as primitive attributes and invalidates them after commits.
 *
 * @internal
 */
final readonly class SettingCache
{
    /**
     * Create the settings record cache.
     */
    public function __construct(
        private Factory $cache,
        private DatabaseManager $database,
        private DefinitionRepository $definitions,
        private OwnershipBoundary $boundary,
    ) {}

    /**
     * Return all persisted setting records through the configured cache.
     *
     * @return Collection<int, Setting>
     */
    public function records(): Collection
    {
        if (! (bool) config('nvl-settings.cache.enabled', true)
            || $this->database->connection((SettingModelRegistry::newInstance())->getConnectionName())->transactionLevel() > 0) {
            return $this->fetch();
        }

        $identity = $this->identity();
        $store = $this->cache->store($identity->store);
        $key = $identity->key;
        $payload = $store->get($key);

        if (! $this->isValidPayload($payload)) {
            if ($payload !== null) {
                $store->forget($key);
            }

            $payload = $store->rememberForever(
                $key,
                fn (): array => $this->fetchPayload(),
            );
        }

        return collect($payload)->map(function (array $attributes): Setting {
            $setting = SettingModelRegistry::newInstance();
            $setting->setRawAttributes($attributes, true);
            $setting->exists = true;
            if ($setting->getRawOriginal('owner_scope_key') !== null) {
                $this->boundary->assertRecord($setting, 'settings.values');
            }

            return $setting;
        })->values();
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
        $hashes = array_map(
            static fn ($definition): string => $definition->hash(),
            $this->definitions->all(),
        );
        sort($hashes);

        $attributes = $this->boundary->attributes('settings.values');
        $ownership = isset($attributes['owner_scope_key'])
            ? $this->boundary->key('settings.values', 'records')
            : 'disabled';

        return $this->makeIdentity($model, $ownership, $hashes);
    }

    /** Derive a stable identity directly from a persisted row. */
    public function identityFor(Setting $setting): SettingCacheIdentity
    {
        $hashes = array_map(
            static fn ($definition): string => $definition->hash(),
            $this->definitions->all(),
        );
        sort($hashes);

        $scope = $setting->getRawOriginal('owner_scope_key');
        if ($scope !== null) {
            if ($scope !== ($this->boundary->attributes('settings.values')['owner_scope_key'] ?? null)) {
                throw new OwnershipViolation('Cache invalidation must capture the persisted record partition.');
            }
        }

        return $this->makeIdentity(
            $setting,
            $scope !== null ? $this->boundary->key('settings.values', 'records') : 'disabled',
            $hashes,
        );
    }

    /** Forget one previously captured cache identity. */
    public function flushIdentity(SettingCacheIdentity $identity): void
    {
        $this->cache->store($identity->store)->forget($identity->key);
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

        $connection->afterCommit(fn (): bool => $this->cache
            ->store($identity->store)
            ->forget($identity->key));
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
    private function fetchPayload(): array
    {
        return array_values($this->fetch()
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
     * @param  list<string>  $definitionHashes
     */
    private function makeIdentity(Setting $setting, string $ownership, array $definitionHashes): SettingCacheIdentity
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
                $definitionHashes,
            ], JSON_THROW_ON_ERROR)),
        ]));
    }
}
