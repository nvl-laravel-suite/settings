<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Services\SettingModelRegistry;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Settings\Tests\Fixtures\UsesActualSettingsConnection;
use Nvl\Settings\Tests\SettingsCacheTestCase;

uses(SettingsCacheTestCase::class, UsesActualSettingsConnection::class, InteractsWithSettings::class);

it('keeps uncommitted values from an overridden model connection out of the shared cache', function (): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    $repository = app(SettingRepository::class);
    $cache = app(SettingCache::class);
    $connection = SettingModelRegistry::newInstance()->getConnection();
    expect($connection->getName())->toBe('settings-actual')
        ->and($connection)->not->toBe(DB::connection());
    $generation = $cache->identity()->key.':generation';
    $connection->beginTransaction();

    try {
        settingsSetForTest('catalog.enabled', true);
        expect($repository->get('catalog.enabled'))->toBeTrue()
            ->and(Cache::store(config('nvl-settings.cache.store'))->has($generation))->toBeFalse();
    } finally {
        $connection->rollBack();
    }

    expect(SettingModelRegistry::query()->count())->toBe(0)
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('invalidates the overridden model cache only after its outer commit and retains it after rollback', function (): void {
    $this->defineSettings(['catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    settingsSetForTest('catalog.enabled', true);
    $repository = app(SettingRepository::class);
    expect($repository->get('catalog.enabled'))->toBeTrue();
    $connection = SettingModelRegistry::newInstance()->getConnection();
    $generation = app(SettingCache::class)->identity()->key.':generation';
    $store = Cache::store(config('nvl-settings.cache.store'));
    $connection->beginTransaction();

    try {
        settingsSetForTest('catalog.enabled', false, 1);
        expect($store->has($generation))->toBeTrue()
            ->and($repository->get('catalog.enabled'))->toBeFalse();
    } finally {
        $connection->rollBack();
    }

    expect($store->has($generation))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeTrue();
    $connection->beginTransaction();
    try {
        settingsSetForTest('catalog.enabled', false, 1);
        expect($store->has($generation))->toBeTrue();
        $connection->commit();
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    expect($store->has($generation))->toBeFalse()
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});
