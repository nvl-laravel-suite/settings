<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Nvl\Settings\Models\Setting;

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
    ) {}

    /**
     * Return all persisted setting records through the configured cache.
     *
     * @return Collection<int, Setting>
     */
    public function records(): Collection
    {
        if (! (bool) config('settings.cache.enabled', true)) {
            return $this->fetch();
        }

        $store = $this->cache->store($this->store());
        $key = $this->key();
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
        $this->cache->store($this->store())->forget($this->key());
    }

    /**
     * Forget the cached records after the active outer transaction commits.
     */
    public function flushAfterCommit(): void
    {
        $connection = $this->database->connection((new Setting)->getConnectionName());

        if ($connection->transactionLevel() === 0) {
            $this->flush();

            return;
        }

        $connection->afterCommit(fn (): bool => $this->cache
            ->store($this->store())
            ->forget($this->key()));
    }

    /**
     * Fetch settings from storage without hiding database failures.
     *
     * @return Collection<int, Setting>
     */
    private function fetch(): Collection
    {
        return Setting::query()->get();
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
}
