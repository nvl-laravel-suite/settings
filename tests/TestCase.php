<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Settings in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            DataServiceProvider::class,
            TenancyServiceProvider::class,
            SettingsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('settings.discovery.paths', [__DIR__.'/Fixtures/settings']);
        $app['config']->set('settings.discovery.cache', false);
        $app['config']->set('settings.cache.enabled', true);
        $app['config']->set('tenancy.enabled', false);
        $app['config']->set('tenancy.migrations.enabled', false);
    }
}
