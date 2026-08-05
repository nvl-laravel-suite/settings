<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Settings\Providers\SettingsServiceProvider;
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
            DataServiceProvider::class,
            SettingsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('settings.discovery.paths', [__DIR__.'/Fixtures/settings']);
        $app['config']->set('settings.discovery.cache', false);
        $app['config']->set('settings.cache.enabled', true);
    }
}
