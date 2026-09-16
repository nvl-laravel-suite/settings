<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Support\Definition;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\TenantInstallationState;

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
        private TenantInstallationState $installation,
    ) {}

    /**
     * Determine whether an unadopted legacy platform store exists.
     *
     * @throws TenantSchemaNotReady When persisted adoption or partial ownership schema exists
     */
    public function available(): bool
    {
        $setting = new Setting;
        $connection = $setting->getConnection();
        $this->installation->assertUnadopted($connection);
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($setting->getTable())) {
            return false;
        }

        if ($schema->hasColumn($setting->getTable(), 'ownership_key')
            || $schema->hasColumn($setting->getTable(), 'tenant_id')) {
            throw new TenantSchemaNotReady('The Settings ownership schema is not active for this deployment.');
        }

        return true;
    }

    /**
     * Return legacy platform rows limited to config-mapped definitions.
     *
     * @return Collection<int, Setting>
     *
     * @throws TenantSchemaNotReady When persisted adoption or partial ownership schema exists
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

        return Setting::query()
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
