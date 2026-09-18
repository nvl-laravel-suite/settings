<?php

declare(strict_types=1);

namespace Nvl\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\UnknownSettingException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Services\SettingValueValidator;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Resolves validated setting definitions against database-backed overrides.
 */
final class SettingManager implements SettingRepository
{
    /**
     * Create the settings repository.
     */
    public function __construct(
        private readonly DefinitionRepository $definitions,
        private readonly SettingCache $cache,
        private readonly SettingValueValidator $values,
        private readonly SettingsAuditContextProvider $auditContext,
        private readonly TenantBoundary $boundary,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Return the effective value for one registered setting.
     */
    public function get(string $key): mixed
    {
        $definition = $this->definitions->get($key);

        $record = $this->cache->records()
            ->first(fn (Setting $setting): bool => $setting->fullKey() === $key);

        if ($record instanceof Setting) {
            return $record->resolved();
        }

        return $definition->default;
    }

    /**
     * Validate and persist one setting override.
     */
    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Validate and atomically persist multiple setting overrides.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        /** @var list<array{
         *     definition: Definition,
         *     value: mixed,
         *     serializedValue: string|null,
         *     serializedFallback: string|null,
         *     serializedMetadata: string,
         *     definitionHash: string
         * }> $prepared
         */
        $prepared = [];

        foreach ($values as $fullKey => $settingValue) {
            $definition = $this->definitions->get($fullKey);
            $ownership = $this->boundary->attributes('settings.values');
            if (isset($ownership['tenant_id']) && ! $definition->tenantOverride) {
                throw new UnknownSettingException("Setting [$fullKey] does not permit tenant overrides.");
            }
            $this->values->validate($definition, $settingValue, $fullKey);
            $prepared[] = [
                'definition' => $definition,
                'value' => $settingValue,
                'serializedValue' => $definition->type->serialize($settingValue),
                'serializedFallback' => $definition->type->serialize($definition->default),
                'serializedMetadata' => json_encode(
                    $definition->metadata,
                    JSON_THROW_ON_ERROR,
                ),
                'definitionHash' => $definition->hash(),
            ];
        }

        $connection = DB::connection((new Setting)->getConnectionName());
        $connection->transaction(function () use ($connection, $prepared): void {
            $changed = false;

            foreach ($prepared as $item) {
                $definition = $item['definition'];
                $settingValue = $item['value'];
                $setting = $this->boundary->query(Setting::query(), 'settings.values')->where([
                    'namespace' => $definition->namespace,
                    'scope' => $definition->scope,
                    'key' => $definition->key,
                ])->lockForUpdate()->first();
                $inserted = false;

                if (! $setting instanceof Setting) {
                    $inserted = Setting::query()->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        ...$this->boundary->attributes('settings.values'),
                        'namespace' => $definition->namespace,
                        'scope' => $definition->scope,
                        'key' => $definition->key,
                        'type' => $definition->type->value,
                        'value' => $item['serializedValue'],
                        'has_override' => true,
                        'fallback' => $item['serializedFallback'],
                        'metadata' => $item['serializedMetadata'],
                        'definition_hash' => $item['definitionHash'],
                        'revision' => 1,
                        'valid_from' => null,
                        'valid_until' => null,
                        'synced_at' => now(),
                        'orphaned_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]) === 1;
                    $setting = $this->boundary->query(Setting::query(), 'settings.values')->where([
                        'namespace' => $definition->namespace,
                        'scope' => $definition->scope,
                        'key' => $definition->key,
                    ])->lockForUpdate()->firstOrFail();
                }

                $settingChanged = $inserted;

                if (! $inserted) {
                    $setting->fill([
                        'namespace' => $definition->namespace,
                        'scope' => $definition->scope,
                        'key' => $definition->key,
                        'type' => $definition->type,
                        'value' => $settingValue,
                        'has_override' => true,
                        'fallback' => $definition->default,
                        'metadata' => $definition->metadata,
                        'definition_hash' => $item['definitionHash'],
                        'orphaned_at' => null,
                        'valid_from' => null,
                        'valid_until' => null,
                    ]);

                    if ($setting->isDirty()) {
                        $setting->synced_at = now();
                        $setting->save();
                        $settingChanged = true;
                    }
                }

                if ($settingChanged) {
                    $changed = true;
                    $id = $setting->id;
                    $key = $setting->fullKey();
                    $revision = $setting->revision;
                    $tenantId = is_string($setting->tenant_id) ? $setting->tenant_id : null;
                    $ownershipKey = is_string($setting->ownership_key) ? $setting->ownership_key : 'platform';
                    $context = $this->auditContext->current();
                    $event = new SettingChanged($id, $key, $revision, 'set', $context, $tenantId, $ownershipKey, TenantJobEnvelope::capture($this->tenantContext));
                    $connection->afterCommit(static fn () => event($event));
                }
            }

            if ($changed) {
                $this->cache->flushAfterCommit();
            }
        });
    }

    /**
     * Clear an override so the synchronized definition fallback is used.
     */
    public function forget(string $key): void
    {
        $definition = $this->definitions->get($key);
        $connection = DB::connection((new Setting)->getConnectionName());

        $connection->transaction(function () use ($connection, $definition): void {
            $setting = $this->boundary->query(Setting::query(), 'settings.values')->where([
                'namespace' => $definition->namespace,
                'scope' => $definition->scope,
                'key' => $definition->key,
            ])->lockForUpdate()->first();

            if (! $setting instanceof Setting || ! $setting->isCustomised()) {
                return;
            }

            $setting->value = null;
            $setting->has_override = false;
            $setting->valid_from = null;
            $setting->valid_until = null;
            $setting->save();
            $this->cache->flushAfterCommit();
            $context = $this->auditContext->current();
            $id = $setting->id;
            $fullKey = $setting->fullKey();
            $revision = $setting->revision;
            $tenantId = is_string($setting->tenant_id) ? $setting->tenant_id : null;
            $ownershipKey = is_string($setting->ownership_key) ? $setting->ownership_key : 'platform';
            $event = new SettingChanged($id, $fullKey, $revision, 'reset', $context, $tenantId, $ownershipKey, TenantJobEnvelope::capture($this->tenantContext));
            $connection->afterCommit(static fn () => event($event));
        });
    }

    /**
     * Determine whether a definition exists.
     */
    public function has(string $key): bool
    {
        try {
            $this->definitions->get($key);

            return true;
        } catch (UnknownSettingException) {
            return false;
        }
    }
}
