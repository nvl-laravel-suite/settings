<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Settings\Tests\SettingsCacheTestCase;

uses(SettingsCacheTestCase::class, InteractsWithSettings::class);

it('preserves rows while widening leaves and refuses unsafe narrowing', function (): void {
    $longLeaf = 'catalog.long.'.str_repeat('a', 90).'.'.str_repeat('b', 60);
    $this->defineSettings([
        'catalog.short' => ['type' => SettingType::Text, 'default' => 'none'],
        $longLeaf => ['type' => SettingType::Text, 'default' => 'none'],
    ]);
    $migration = require dirname(__DIR__).'/database/migrations/2026_11_16_003346_nvl_settings_widen_setting_leaf_keys.php';
    $setting = new Setting;
    $schema = Schema::connection($setting->getConnectionName());
    $table = $setting->getTable();
    $keyType = static fn (): string => collect($schema->getColumns($table))->firstWhere('name', 'key')['type'];
    $rows = static fn (): array => Setting::query()->orderBy('key')->get()
        ->map(static fn (Setting $row): array => [$row->id, $row->key, $row->value, $row->revision])->all();
    $postgres = $schema->getConnection()->getDriverName() === 'pgsql';

    try {
        settingsSetForTest('catalog.short', 'kept');
        $short = $rows();
        $migration->down();
        expect($rows())->toBe($short);
        if ($postgres) {
            expect($keyType())->toBe('character varying(100)');
        }

        $migration->up();
        expect($rows())->toBe($short);
        if ($postgres) {
            expect($keyType())->toBe('character varying(255)');
        }

        settingsSetForTest($longLeaf, 'long');
        $widened = $rows();
        expect(fn () => $migration->down())->toThrow(LogicException::class, 'Remove or rename setting leaves longer than 100 characters')
            ->and($rows())->toBe($widened)
            ->and($widened)->toHaveCount(2);
        if ($postgres) {
            expect($keyType())->toBe('character varying(255)');
        }
    } finally {
        Setting::query()->delete();
    }
});
