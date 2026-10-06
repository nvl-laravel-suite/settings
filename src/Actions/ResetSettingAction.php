<?php

declare(strict_types=1);

namespace Nvl\Settings\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Settings\Contracts\ResetSettingContract;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Data\SettingValueData;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Clears one override while preserving its synchronized definition fallback.
 *
 * @api
 */
final readonly class ResetSettingAction implements ResetSettingContract
{
    /**
     * Create the optimistic reset action.
     */
    public function __construct(
        private DefinitionRepository $definitions,
        private SettingCache $cache,
        private SettingsAuditContextProvider $auditContext,
        private TenantBoundary $boundary,
        private TenantContext $tenantContext,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * Clear one override when its optimistic revision still matches.
     */
    public function execute(string $key, int $expectedRevision): SettingValueData
    {
        $definition = $this->definitions->get($key);
        $model = new Setting;
        $connection = DB::connection($model->getConnectionName());
        $setting = $connection
            ->transaction(function () use (
                $connection,
                $definition,
                $key,
                $expectedRevision,
            ): Setting {
                $setting = $this->boundary->query(Setting::query(), 'settings.values')->where([
                    'namespace' => $definition->namespace,
                    'scope' => $definition->scope,
                    'key' => $definition->key,
                ])->lockForUpdate()->firstOrFail();

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
                $id = $setting->id;
                $fullKey = $setting->fullKey();
                $revision = $setting->revision;
                $tenantId = is_string($setting->tenant_id) ? $setting->tenant_id : null;
                $ownershipKey = is_string($setting->ownership_key) ? $setting->ownership_key : 'platform';
                $context = $this->auditContext->current();
                $event = new SettingChanged($id, $fullKey, $revision, 'reset', $context, $tenantId, $ownershipKey, TenantJobEnvelope::capture($this->tenantContext));
                $this->domainEvents->dispatch($event, $connection);

                return $setting;
            });

        return SettingValueData::fromModel($setting);
    }
}
