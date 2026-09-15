<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Testing\InteractsWithSettings;
use Nvl\Settings\Tests\SettingsCacheTestCase;

uses(InteractsWithSettings::class);

it('caches only primitive setting payloads on serialized stores', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $key = 'nvl:settings:test:'.Str::uuid();
    config()->set('settings.cache.store', 'file');
    config()->set('settings.cache.key', $key);
    config()->set('cache.serializable_classes', false);
    $repository = app(SettingRepository::class);

    try {
        $repository->set('catalog.enabled', true);

        expect($repository->get('catalog.enabled'))->toBeTrue();

        $payload = Cache::store('file')->get($key);

        expect($payload)->toBeArray()
            ->and($payload[0])->toBeArray()
            ->and($payload[0]['value'])->toBe('1')
            ->and($repository->get('catalog.enabled'))->toBeTrue();
    } finally {
        Cache::store('file')->forget($key);
    }
});

it('rebuilds malformed primitive cache payloads before model hydration', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $key = 'nvl:settings:test:'.Str::uuid();
    config()->set('settings.cache.store', 'array');
    config()->set('settings.cache.key', $key);
    $repository = app(SettingRepository::class);
    $repository->set('catalog.enabled', true);
    $repository->get('catalog.enabled');
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
    $repository->set('catalog.enabled', true);
    expect($repository->get('catalog.enabled'))->toBeTrue();
    $key = config('settings.cache.key');
    $connection = DB::connection((new Setting)->getConnectionName());

    $connection->beginTransaction();
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();

    expect(Cache::store(config('settings.cache.store'))->has($key))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeFalse();

    $connection->rollBack();

    expect(Cache::store(config('settings.cache.store'))->has($key))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeTrue();

    $connection->beginTransaction();
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();
    $connection->commit();

    expect(Cache::store(config('settings.cache.store'))->has($key))->toBeFalse()
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('does not publish uncommitted settings into an empty shared cache', function (): void {
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    $key = config('settings.cache.key');
    $connection = DB::connection((new Setting)->getConnectionName());
    $connection->beginTransaction();

    try {
        $repository->set('catalog.enabled', true);
        expect($repository->get('catalog.enabled'))->toBeTrue()
            ->and(Cache::store(config('settings.cache.store'))->has($key))->toBeFalse();
    } finally {
        $connection->rollBack();
    }

    expect(Setting::query()->count())->toBe(0)
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});
