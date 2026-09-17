<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Tenancy\Services\TenantBoundary;

/**
 * Caches setting records as primitive attributes and invalidates them after commits.
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
        private TenantBoundary $boundary,
    ) {}

    /**
     * Return all persisted setting records through the configured cache.
     *
     * @return Collection<int, Setting>
     */
    public function records(): Collection
    {
        if (! (bool) config('settings.cache.enabled', true)
            || $this->database->connection((new Setting)->getConnectionName())->transactionLevel() > 0) {
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
            $setting = new Setting;
            $setting->setRawAttributes($attributes, true);
            $setting->exists = true;

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
        $connection = $this->database->connection((new Setting)->getConnectionName());
        $hashes = array_map(
            static fn ($definition): string => $definition->hash(),
            $this->definitions->all(),
        );
        sort($hashes);

        $ownership = $this->boundary->attributes('settings.values')['ownership_key'] ?? 'disabled';

        return $this->makeIdentity($connection->getName(), $ownership, $hashes);
    }

    /** Derive a stable identity directly from a persisted row. */
    public function identityFor(Setting $setting): SettingCacheIdentity
    {
        $hashes = array_map(
            static fn ($definition): string => $definition->hash(),
            $this->definitions->all(),
        );
        sort($hashes);

        return $this->makeIdentity(
            $setting->getConnection()->getName(),
            (string) ($setting->getRawOriginal('ownership_key') ?: 'disabled'),
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
        $connection = $this->database->connection((new Setting)->getConnectionName());
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
        return $this->boundary->query(Setting::query(), 'settings.values')->get();
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
            'tenant_id',
            'ownership_key',
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
        $store = config('settings.cache.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * Return the validated settings cache key.
     */
    private function key(): string
    {
        $key = config('settings.cache.key', 'nvl:settings:v2');

        return is_string($key) && $key !== '' ? $key : 'nvl:settings:v2';
    }

    /** Build one deterministic cache key without consulting ambient context. */
    private function makeIdentity(string $connection, string $ownership, array $definitionHashes): SettingCacheIdentity
    {
        return new SettingCacheIdentity($this->store(), implode(':', [
            $this->key(),
            hash('sha256', implode('|', [$connection, $ownership, ...$definitionHashes])),
        ]));
    }
}
