<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Foundation\Application;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Applies platform configuration only during Laravel application bootstrap.
 *
 * @internal
 */
final readonly class PlatformSettingsBootstrap
{
    /**
     * Create the platform bootstrap projection.
     */
    public function __construct(
        private Application $app,
        private PlatformSettingsReader $settings,
        private PlatformConfigWriter $writer,
    ) {}

    /**
     * Apply available platform settings before the application finishes booting.
     *
     * @throws TenantBoundaryViolation When invoked after application bootstrap
     */
    public function apply(): void
    {
        if ($this->app->isBooted()) {
            throw new TenantBoundaryViolation('Platform configuration bootstrap has ended.');
        }

        if (! (bool) config('settings.overrides.enabled', false)
            || ! $this->settings->available()) {
            return;
        }

        $this->writer->apply($this->settings->records());
    }
}
