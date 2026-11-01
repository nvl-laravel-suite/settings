<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Settings\Services\SettingModelRegistry;

/** Exercise a real model connection override with its own transaction counter. */
trait UsesActualSettingsConnection
{
    protected function defineEnvironment($app): void
    {
        $morphMap = Relation::morphMap();
        $this->beforeApplicationDestroyed(static function () use ($morphMap): void {
            Relation::morphMap($morphMap, false);
        });
        parent::defineEnvironment($app);
        $default = $app['config']->get('database.default');
        $app['config']->set('database.connections.settings-actual', $app['config']->get('database.connections.'.$default));
        $app['config']->set('nvl-settings.models.setting', ActualConnectionSetting::class);
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $model = SettingModelRegistry::newInstance();
        if ($model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            return;
        }

        $configured = config('nvl-settings.connection');
        config()->set('nvl-settings.connection', 'settings-actual');
        try {
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
            $migration->up();
        } finally {
            config()->set('nvl-settings.connection', $configured);
        }
    }
}
