<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipScope;

/**
 * Reads only config-mapped records from the canonical legacy platform store.
 *
 * @internal
 */
final readonly class PlatformSettingsReader
{
    /**
     * Create the guarded platform settings reader.
     */
    public function __construct(
        private DefinitionRepository $definitions,
        private OwnershipReadiness $ownership,
        private OwnershipResourceRegistry $resources,
    ) {}

    /**
     * Determine whether an unadopted legacy platform store exists.
     *
     * @throws OwnershipConfigurationInvalid When storage and declared ownership disagree
     */
    public function available(): bool
    {
        $setting = new Setting;
        $connection = $setting->getConnection();
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($setting->getTable())) {
            return false;
        }
        $captured = $this->resources->get('settings.values')->ownership->strategy === 'captured';
        if ($captured) {
            $this->ownership->assertActive('settings.values');

            return true;
        }

        if ($schema->hasColumn($setting->getTable(), 'owner_scope_key')
            || $schema->hasColumn($setting->getTable(), 'owner_reference')
            || $schema->hasColumn($setting->getTable(), 'tenant_id')
            || $schema->hasColumn($setting->getTable(), 'ownership_key')) {
            throw new OwnershipConfigurationInvalid('Settings ownership storage exists without an active ownership declaration.');
        }

        return true;
    }

    /**
     * Return legacy platform rows limited to config-mapped definitions.
     *
     * @return Collection<int, Setting>
     *
     * @throws OwnershipConfigurationInvalid When storage and declared ownership disagree
     */
    public function records(): Collection
    {
        if (! $this->available()) {
            return (new Setting)->newCollection();
        }

        $definitions = array_filter(
            $this->definitions->all(),
            static fn (Definition $definition): bool => $definition->overrides !== null,
        );

        if ($definitions === []) {
            return (new Setting)->newCollection();
        }

        $query = Setting::query()->withoutGlobalScope(OwnershipScope::class)->useWritePdo();
        if ($this->resources->get('settings.values')->ownership->strategy === 'captured') {
            $query->where('owner_scope_key', 'central')->whereNull('owner_reference');
        }

        return $query
            ->where(function (Builder $query) use ($definitions): void {
                foreach ($definitions as $definition) {
                    $query->orWhere(function (Builder $definitionQuery) use ($definition): void {
                        $definitionQuery
                            ->where('namespace', $definition->namespace)
                            ->where('scope', $definition->scope)
                            ->where('key', $definition->key);
                    });
                }
            })
            ->get();
    }
}
