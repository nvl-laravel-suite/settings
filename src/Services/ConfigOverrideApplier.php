<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

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
    ) {}

    /**
     * Apply every allowed definition mapping, including unsynchronized defaults.
     *
     * @throws TenantBoundaryViolation When invoked from tenant or unresolved runtime context
     */
    public function apply(): void
    {
        $mode = $this->context->snapshot()->mode;
        if ($mode === TenantContextMode::Tenant || $mode === TenantContextMode::Unresolved) {
            throw new TenantBoundaryViolation('Settings overrides require the platform bootstrap boundary.');
        }

        if (! (bool) config('settings.overrides.enabled', false)
            || ! $this->settings->available()) {
            return;
        }

        $this->writer->apply($this->settings->records());
    }
}
