<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Settings\Tests\SettingsCacheTestCase;

uses(SettingsCacheTestCase::class, InteractsWithSettings::class);

it('stores validity windows verbatim beyond 2038 and at every UTC instant', function (): void {
    $this->defineSettings(['interface.theme' => ['type' => SettingType::Text, 'default' => 'light']]);

    try {
        $stored = settingsSetForTest('interface.theme', 'dark', 0, [
            'valid_from' => '2027-03-28T03:30:00Z',
            'valid_until' => '2099-12-31T00:00:00Z',
        ]);
        $record = Setting::query()->firstOrFail();

        expect($stored['valid_from'])->toBe('2027-03-28T03:30:00+00:00')
            ->and($stored['valid_until'])->toBe('2099-12-31T00:00:00+00:00')
            ->and($record->getRawOriginal('valid_from'))->toBe('2027-03-28 03:30:00')
            ->and($record->getRawOriginal('valid_until'))->toBe('2099-12-31 00:00:00')
            ->and(app(SettingReader::class)->get('interface.theme')['valid_until'])->toBe('2099-12-31T00:00:00+00:00');
    } finally {
        Setting::query()->delete();
    }
});

it('preserves the existing validity storage and rows when replaying the retained migration', function (): void {
    $this->defineSettings(['interface.theme' => ['type' => SettingType::Text, 'default' => 'light']]);
    $migration = require dirname(__DIR__).'/database/migrations/2026_11_11_000000_nvl_settings_store_setting_validity_as_datetime.php';
    $setting = new Setting;
    $schema = Schema::connection($setting->getConnectionName());
    $table = $setting->getTable();
    $type = static fn (): string => $schema->getColumnType($table, 'valid_until');
    $initial = $type();

    try {
        settingsSetForTest('interface.theme', 'dark', 0, ['valid_until' => '2030-01-01T00:00:00Z']);
        $migration->down();
        $narrowed = $type();
        $migration->up();

        expect($narrowed)->toBe($initial)
            ->and($type())->toBe($initial)
            ->and(Setting::query()->firstOrFail()->getRawOriginal('valid_until'))->toBe('2030-01-01 00:00:00');

        settingsSetForTest('interface.theme', 'dark', 1, ['valid_until' => '2099-12-31T00:00:00Z']);
        $migration->down();
        expect($type())->toBe($initial);
    } finally {
        Setting::query()->delete();
    }
});

it('leaves a missing or foreign settings table untouched', function (): void {
    $migration = require dirname(__DIR__).'/database/migrations/2026_11_11_000000_nvl_settings_store_setting_validity_as_datetime.php';
    $create = require dirname(__DIR__).'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    $schema = Schema::connection((new Setting)->getConnectionName());
    $table = (new Setting)->getTable();
    $create->down();

    try {
        $migration->up();
        $migration->down();
        expect($schema->hasTable($table))->toBeFalse();

        $schema->create($table, function (Blueprint $blueprint): void {
            $blueprint->string('key')->primary();
            $blueprint->text('value')->nullable();
        });
        $migration->up();
        $migration->down();
        expect($schema->getColumnListing($table))->toBe(['key', 'value']);
    } finally {
        $schema->dropIfExists($table);
        $create->up();
        $migration->up();
    }
});
