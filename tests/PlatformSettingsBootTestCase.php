<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

/**
 * Reboots the real Settings provider against one persistent configured platform store.
 */
abstract class PlatformSettingsBootTestCase extends Orchestra
{
    use DatabaseMigrations;

    protected bool $enableBootstrap = false;

    protected ?bool $enableTenancy = null;

    protected string $databasePath;

    private string $temporaryDatabasePath;

    /**
     * Create the persistent database before the first application boot.
     */
    protected function setUp(): void
    {
        if (getenv('NVL_FULL_DATABASE') === '1' && getenv('DB_CONNECTION') !== 'sqlite') {
            $this->databasePath = getenv('DB_DATABASE') ?: 'testing';
            parent::setUp();

            return;
        }

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
                $this->app->make('db')->purge($this->app['config']->get('database.default'));
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
            LocaleServiceProvider::class,
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
        $driver = getenv('NVL_FULL_DATABASE') === '1' ? (getenv('DB_CONNECTION') ?: 'sqlite') : 'sqlite';

        $app['config']->set([
            'database.default' => $driver,
            'database.connections.'.$driver.'.database' => $this->databasePath,
            'database.connections.'.$driver.'.url' => null,
            'app.name' => 'Original platform',
            'nvl-settings.discovery.paths' => [__DIR__.'/Fixtures/platform-settings'],
            'nvl-settings.discovery.cache' => false,
            'nvl-settings.cache.enabled' => false,
            'nvl-settings.overrides.enabled' => $this->enableBootstrap,
            'nvl-tenancy.enabled' => $this->enableTenancy ?? $this->enableBootstrap,
            'nvl-tenancy.migrations.enabled' => false,
        ]);
    }
}
