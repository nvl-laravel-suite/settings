<?php

declare(strict_types=1);

namespace Nvl\Settings\Ownership;

use Nvl\Settings\Models\Setting;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Declares internal captured values while code definitions remain global.
 *
 * @internal
 */
final readonly class SettingsResourceRegistrar
{
    /** Compile the explicit deployment classification before the graph freezes. */
    public function register(OwnershipResourceRegistry $resources, bool $enabled): void
    {
        $resources->register(new OwnershipResourceDefinition('settings.values', 'settings', Setting::class, $enabled ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $enabled));
    }
}
