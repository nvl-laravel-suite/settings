<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Contracts\Foundation\Application;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

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
     * @throws OwnershipViolation When invoked after application bootstrap
     */
    public function apply(): void
    {
        if ($this->app->isBooted()) {
            throw new OwnershipViolation('Platform configuration bootstrap has ended.');
        }

        if (! (bool) config('nvl-settings.overrides.enabled', false)) {
            return;
        }

        $records = $this->settings->records();
        if ($records !== null) {
            $this->writer->apply($records);
        }
    }
}
