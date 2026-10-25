<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
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
            LocaleServiceProvider::class,
            SupportServiceProvider::class,
            SettingsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nvl-settings.discovery.paths', [__DIR__.'/Fixtures/settings']);
        $app['config']->set('nvl-settings.discovery.cache', false);
        $app['config']->set('nvl-settings.cache.enabled', true);
    }
}
