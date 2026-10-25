<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Applies explicitly mapped effective settings to Laravel configuration.
 */
final readonly class ConfigOverrideApplier
{
    /**
     * Create the configuration override applier.
     */
    public function __construct(
        private PlatformSettingsReader $settings,
        private PlatformConfigWriter $writer,
        private OwnershipContext $ownership,
    ) {}

    /**
     * Apply every allowed definition mapping, including unsynchronized defaults.
     *
     * @throws OwnershipViolation When invoked from owner or unresolved runtime context
     */
    public function apply(): void
    {
        if (in_array($this->ownership->snapshot()->mode, [OwnershipContextMode::Owner, OwnershipContextMode::Unresolved], true)) {
            throw new OwnershipViolation('Configuration projection requires the package bootstrap boundary.');
        }
        if (! (bool) config('nvl-settings.overrides.enabled', false)
            || ! $this->settings->available()) {
            return;
        }

        $this->writer->apply($this->settings->records());
    }
}
