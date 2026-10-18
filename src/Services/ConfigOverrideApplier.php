<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Applies explicitly mapped effective settings to Laravel configuration.
 */
final readonly class ConfigOverrideApplier
{
    /**
     * Create the configuration override applier.
     */
    public function __construct(
        private TenantContext $context,
        private PlatformSettingsReader $settings,
        private PlatformConfigWriter $writer,
        private OwnershipContext $ownership,
    ) {}

    /**
     * Apply every allowed definition mapping, including unsynchronized defaults.
     *
     * @throws TenantBoundaryViolation When invoked from tenant or unresolved runtime context
     */
    public function apply(): void
    {
        if (in_array($this->ownership->snapshot()->mode, [OwnershipContextMode::Owner, OwnershipContextMode::Unresolved], true)) {
            throw new OwnershipViolation('Configuration projection requires the package bootstrap boundary.');
        }
        $mode = $this->context->snapshot()->mode;
        if ($mode === TenantContextMode::Tenant || $mode === TenantContextMode::Unresolved) {
            throw new TenantBoundaryViolation('Settings overrides require the platform bootstrap boundary.');
        }

        if (! (bool) config('nvl-settings.overrides.enabled', false)
            || ! $this->settings->available()) {
            return;
        }

        $this->writer->apply($this->settings->records());
    }
}
