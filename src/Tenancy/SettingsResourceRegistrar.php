<?php

declare(strict_types=1);

namespace Nvl\Settings\Tenancy;

use Nvl\Settings\Models\Setting;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Declares tenant Settings values while definitions remain immutable code. */
final readonly class SettingsResourceRegistrar
{
    /** Register Settings ownership and its package-owned adopter. */
    public function register(TenantResourceRegistry $resources, TenantAdoptionRegistry $adapters): void
    {
        $resources->register(new TenantResourceDefinition(
            key: 'settings.values',
            family: 'settings',
            model: Setting::class,
            allowsPlatformRows: true,
        ));
        $adapters->register('settings', SettingsAdoptionAdapter::class);
    }
}
