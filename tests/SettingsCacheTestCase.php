<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Exercises shared settings caching with real transaction commits and rollbacks.
 */
abstract class SettingsCacheTestCase extends Orchestra
{
    use DatabaseMigrations;

    /**
     * Register the same settings runtime used by the transactional package tests.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DataServiceProvider::class, SettingsServiceProvider::class];
    }

    /**
     * Configure deterministic definitions and enabled shared caching.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('settings.discovery.paths', [__DIR__.'/Fixtures/settings']);
        $app['config']->set('settings.discovery.cache', false);
        $app['config']->set('settings.cache.enabled', true);
    }
}
