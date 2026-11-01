<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Settings\Tests\SettingsCacheTestCase;

uses(InteractsWithSettings::class);

it('caches only primitive setting payloads on serialized stores', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $prefix = 'nvl:settings:test:'.Str::uuid();
    config()->set('nvl-settings.cache.store', 'file');
    config()->set('nvl-settings.cache.key', $prefix);
    $key = null;
    config()->set('cache.serializable_classes', false);
    $repository = app(SettingRepository::class);

    try {
        settingsSetForTest('catalog.enabled', true);

        expect($repository->get('catalog.enabled'))->toBeTrue();

        $key = app(SettingCache::class)->recordIdentity('catalog.enabled')->key;
        $payload = Cache::store('file')->get($key);

        expect($payload)->toBeArray()
            ->and($payload[0])->toBeArray()
            ->and($payload[0]['value'])->toBe('1')
            ->and($repository->get('catalog.enabled'))->toBeTrue();
    } finally {
        if (is_string($key)) {
            Cache::store('file')->forget($key);
        }
        app(SettingCache::class)->flush();
    }
});

it('rebuilds malformed primitive cache payloads before model hydration', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $prefix = 'nvl:settings:test:'.Str::uuid();
    config()->set('nvl-settings.cache.store', 'array');
    config()->set('nvl-settings.cache.key', $prefix);
    $repository = app(SettingRepository::class);
    settingsSetForTest('catalog.enabled', true);
    $repository->get('catalog.enabled');
    $key = app(SettingCache::class)->recordIdentity('catalog.enabled')->key;
    $malformed = Cache::store('array')->get($key);
    $malformed[0]['value'] = 1.25;
    Cache::store('array')->forever($key, $malformed);

    expect($repository->get('catalog.enabled'))->toBeTrue()
        ->and(app(SettingCache::class)->records())->toHaveCount(1)
        ->and(Cache::store('array')->get($key))->toBeArray()
        ->and(Cache::store('array')->get($key)[0])->toHaveKeys([
            'id',
            'namespace',
            'scope',
            'key',
            'type',
            'value',
            'has_override',
            'fallback',
            'metadata',
            'definition_hash',
            'revision',
        ]);
});

it('invalidates cached settings only after the outer transaction commits', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    settingsSetForTest('catalog.enabled', true);
    expect($repository->get('catalog.enabled'))->toBeTrue();
    $key = app(SettingCache::class)->identity()->key.':generation';
    $connection = DB::connection((new Setting)->getConnectionName());

    $connection->beginTransaction();
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();

    expect(Cache::store(config('nvl-settings.cache.store'))->has($key))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeFalse();

    $connection->rollBack();

    expect(Cache::store(config('nvl-settings.cache.store'))->has($key))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeTrue();

    $connection->beginTransaction();
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();
    $connection->commit();

    expect(Cache::store(config('nvl-settings.cache.store'))->has($key))->toBeFalse()
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('does not publish uncommitted settings into an empty shared cache', function (): void {
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    $key = app(SettingCache::class)->identity()->key.':generation';
    $connection = DB::connection((new Setting)->getConnectionName());
    $connection->beginTransaction();

    try {
        settingsSetForTest('catalog.enabled', true);
        expect($repository->get('catalog.enabled'))->toBeTrue()
            ->and(Cache::store(config('nvl-settings.cache.store'))->has($key))->toBeFalse();
    } finally {
        $connection->rollBack();
    }

    expect(Setting::query()->count())->toBe(0)
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('does not share unowned cached values between configured storage tables', function (): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    $table = (new Setting)->getTable();
    settingsSetForTest('catalog.enabled', true);
    config()->set('nvl-settings.tables.settings', 'alternate_settings');
    $migration = require dirname(__DIR__).'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';
    $migration->up();

    try {
        settingsSetForTest('catalog.enabled', false);
        config()->set('nvl-settings.tables.settings', $table);
        app(SettingCache::class)->flush();
        $repository = app(SettingRepository::class);
        expect($repository->get('catalog.enabled'))->toBeTrue();
        config()->set('nvl-settings.tables.settings', 'alternate_settings');
        expect($repository->get('catalog.enabled'))->toBeFalse();
    } finally {
        Schema::dropIfExists('alternate_settings');
        config()->set('nvl-settings.tables.settings', $table);
    }
});

it('bounds single setting fills to the requested identity', function (bool $cached): void {
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
        'catalog.visible' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    settingsSetForTest('catalog.enabled', true);
    settingsSetForTest('catalog.visible', true);
    config()->set('nvl-settings.cache.enabled', $cached);
    $seen = [];
    Setting::retrieved(function (Setting $setting) use (&$seen): void {
        $seen[] = $setting->fullKey();
    });

    expect(app(SettingRepository::class)->get('catalog.enabled'))->toBeTrue()
        ->and($seen)->toBe(['catalog.enabled']);
})->with([false, true]);

it('discards stale cache fills from an invalidated generation and expires their storage', function (): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    config()->set('nvl-settings.cache.ttl', 5);
    settingsSetForTest('catalog.enabled', true);
    $repository = app(SettingRepository::class);
    expect($repository->get('catalog.enabled'))->toBeTrue();
    $key = app(SettingCache::class)->recordIdentity('catalog.enabled')->key;
    $stale = Cache::store(config('nvl-settings.cache.store'))->get($key);
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();
    Cache::store(config('nvl-settings.cache.store'))->put($key, $stale, 5);

    expect($repository->get('catalog.enabled'))->toBeFalse()
        ->and(app(SettingCache::class)->recordIdentity('catalog.enabled')->key)->not->toBe($key);
    $this->travel(6)->seconds();
    expect(Cache::store(config('nvl-settings.cache.store'))->has($key))->toBeFalse()
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('retains bounded runtime reads with a nonpersisting cache store', function (): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    settingsSetForTest('catalog.enabled', true);
    config()->set('cache.stores.null', ['driver' => 'null']);
    config()->set('nvl-settings.cache.store', 'null');

    expect(app(SettingRepository::class)->get('catalog.enabled'))->toBeTrue();
});
