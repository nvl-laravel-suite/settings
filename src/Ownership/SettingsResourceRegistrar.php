<?php

declare(strict_types=1);

namespace Nvl\Settings\Ownership;

use Nvl\Settings\Services\SettingModelRegistry;
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
    public function __construct(private SettingModelRegistry $models) {}

    /** Compile the explicit deployment classification before the graph freezes. */
    public function register(OwnershipResourceRegistry $resources, bool $enabled): void
    {
        $resources->register(new OwnershipResourceDefinition('settings.values', 'settings', $this->models->settingClass(), $enabled ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), allowsCentral: $enabled));
    }
}
