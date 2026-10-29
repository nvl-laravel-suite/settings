<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Ownership\SettingsResourceRegistrar;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Services\SettingModelRegistry;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingWriter;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Tests\Fixtures\ExtendedSetting;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

class SettingsModelQueueProbe
{
    use SerializesModels;

    public function __construct(public Setting $setting) {}
}

class SettingsMissingRevisionHook extends ExtendedSetting
{
    protected static function booted(): void {}
}

class SettingsMissingCast extends ExtendedSetting
{
    protected function casts(): array
    {
        return ['revision' => 'string'];
    }
}

it('uses the configured setting in native writes cached reads factories restoration and revision hooks', function (): void {
    config()->set('nvl-settings.connection', null);
    app(DefinitionRepository::class)->fake(['catalog.label' => ['type' => SettingType::Text, 'default' => 'Original']]);
    $writer = app(SettingWriter::class);
    $created = $writer->set('catalog.label', 'Custom', settingsTestActor(), 0);
    $record = app(SettingCache::class)->records()->sole();

    expect($record)->toBeInstanceOf(ExtendedSetting::class)
        ->and($record->getConnection()->getName())->toBe('settings-root')
        ->and($record->getTable())->toBe('custom_settings')
        ->and($record->displayKey())->toBe('CATALOG.LABEL')
        ->and($created['value'])->toBe('Custom')
        ->and(app(SettingReader::class)->get('catalog.label')['revision'])->toBe(1)
        ->and(Relation::getMorphedModel(Setting::class))->toBe(ExtendedSetting::class)
        ->and(app(OwnershipResourceRegistry::class)->get('settings.values')->model)->toBe(ExtendedSetting::class)
        ->and(app(OwnershipResourceRegistry::class)->get('settings.values')->ownership->strategy)->toBe('unscoped');

    $record->metadata = ['consumer' => true];
    $record->save();
    expect($record->revision)->toBe(2)
        ->and(app(SettingReader::class)->get('catalog.label')['revision'])->toBe(2);
    $restored = unserialize(serialize(new SettingsModelQueueProbe($record)));
    expect($restored->setting)->toBeInstanceOf(ExtendedSetting::class)
        ->and($restored->setting->getConnection()->getName())->toBe('settings-root')
        ->and($restored->setting->metadata)->toBe(['consumer' => true])
        ->and(Setting::factory()->create())->toBeInstanceOf(ExtendedSetting::class);

    $owned = new OwnershipResourceRegistry;
    app(SettingsResourceRegistrar::class)->register($owned, true);
    $owned->freeze();
    expect($owned->get('settings.values')->model)->toBe(ExtendedSetting::class)
        ->and($owned->get('settings.values')->ownership->strategy)->toBe('captured');

    config()->set('nvl-settings.models.setting', Setting::class);
    expect(SettingModelRegistry::modelClass())->toBe(ExtendedSetting::class);
});

it('keeps configured source rollback out of persisted values cache and events', function (): void {
    config()->set('nvl-settings.connection', null);
    app(DefinitionRepository::class)->fake(['catalog.label' => ['type' => SettingType::Text, 'default' => 'Original']]);
    $writer = app(SettingWriter::class);
    $writer->set('catalog.label', 'Committed', settingsTestActor(), 0);
    expect(app(SettingReader::class)->get('catalog.label')['value'])->toBe('Committed');
    Event::fake([SettingChanged::class]);
    $connection = SettingModelRegistry::newInstance()->getConnection();
    $connection->beginTransaction();
    try {
        $writer->set('catalog.label', 'Rolled back', settingsTestActor(), 1);
        expect(app(SettingReader::class)->get('catalog.label')['value'])->toBe('Rolled back');
        Event::assertNotDispatched(SettingChanged::class);
    } finally {
        $connection->rollBack();
    }
    expect(app(SettingReader::class)->get('catalog.label')['value'])->toBe('Committed')
        ->and(SettingModelRegistry::query()->sole()->revision)->toBe(1);
    Event::assertNotDispatched(SettingChanged::class);
});

it('rejects incompatible setting hooks and required casts before persistence', function (string $class, string $reason): void {
    $configuration = new Repository(['nvl-settings' => ['models' => ['setting' => $class]]]);
    $registry = new SettingModelRegistry($configuration, new OwnerRegistry($configuration));
    $map = Relation::morphMap();
    try {
        Relation::morphMap([], false);
        expect(fn () => $registry->settingClass())->toThrow(OwnershipConfigurationInvalid::class, $reason);
    } finally {
        Relation::morphMap($map, false);
    }
    expect(SettingModelRegistry::query()->count())->toBe(0);
})->with([
    [SettingsMissingRevisionHook::class, 'native revision and resource classification hooks'],
    [SettingsMissingCast::class, 'required package casts'],
]);
