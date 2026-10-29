<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Settings\Tests\Fixtures\ExtendedSetting;

/** Exercise complete Settings operations on the configured root's actual connection. */
abstract class ConfiguredModelsTestCase extends TestCase
{
    use DatabaseMigrations;

    public function refreshDatabase(): void {}

    /** @var array<string, class-string<Model>> */
    private array $originalMorphMap = [];

    protected function setUp(): void
    {
        $this->originalMorphMap = Relation::morphMap();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Relation::morphMap($this->originalMorphMap, false);
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.settings-root', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $app['config']->set('nvl-settings.connection', 'settings-root');
        $app['config']->set('nvl-settings.tables.settings', 'custom_settings');
        $app['config']->set('nvl-settings.models.setting', ExtendedSetting::class);
    }
}
