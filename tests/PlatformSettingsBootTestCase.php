<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

/**
 * Reboots the real Settings provider against one persistent SQLite platform store.
 */
abstract class PlatformSettingsBootTestCase extends Orchestra
{
    use DatabaseMigrations;

    protected bool $enableBootstrap = false;

    protected string $databasePath;

    private string $temporaryDatabasePath;

    /**
     * Create the persistent database before the first application boot.
     */
    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'settings-boot-');
        if ($path === false) {
            throw new RuntimeException('Unable to create the Settings boot database.');
        }

        $this->databasePath = $path;
        $this->temporaryDatabasePath = $path;

        parent::setUp();
    }

    /**
     * Close SQLite connections before deleting the persistent database.
     */
    protected function tearDown(): void
    {
        try {
            if (isset($this->app)) {
                $this->app->make('db')->purge('sqlite');
            }

            parent::tearDown();
        } finally {
            if (isset($this->temporaryDatabasePath) && file_exists($this->temporaryDatabasePath)) {
                unlink($this->temporaryDatabasePath);
            }
        }
    }

    /**
     * Register the provider chain used by a Settings consumer with Tenancy installed.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
            TenancyServiceProvider::class,
            SettingsServiceProvider::class,
        ];
    }

    /**
     * Configure every reboot to use the same platform store and fixture definition.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'app.name' => 'Original platform',
            'settings.discovery.paths' => [__DIR__.'/Fixtures/platform-settings'],
            'settings.discovery.cache' => false,
            'settings.cache.enabled' => false,
            'settings.overrides.enabled' => $this->enableBootstrap,
            'tenancy.enabled' => $this->enableBootstrap,
            'tenancy.migrations.enabled' => false,
        ]);
    }
}
