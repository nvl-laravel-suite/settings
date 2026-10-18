<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Settings\SettingManager;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Tests\Fixtures\OwnershipActor;
use Nvl\Settings\Tests\Fixtures\OwnershipCompany;
use Nvl\Settings\Tests\OwnershipTestCase;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);

it('isolates the same setting key and retained cache between native owners', function (): void {
    $manager = app(SettingManager::class);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, function () use ($manager): void {
        $manager->set('interface.theme', 'dark');
        expect($manager->get('interface.theme'))->toBe('dark');
    });
    $runner->run(OwnershipCompany::query()->findOrFail(8), $actor, function () use ($manager): void {
        expect($manager->get('interface.theme'))->toBe('light');
        $manager->set('interface.theme', 'light');
        expect(Setting::query()->count())->toBe(1);
    });
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect($manager->get('interface.theme'))->toBe('dark'));
    expect(fn () => $manager->get('interface.theme'))->toThrow(OwnershipViolation::class);
});

it('projects only central config mapped rows and restores a retained runtime after failure', function (): void {
    app(DefinitionRepository::class)->fake([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Default', 'tenant_override' => true],
    ]);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::query()->firstOrFail();
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => app(SettingManager::class)->set('branding.name', 'Owner private'));
    app(DefinitionRepository::class)->fake([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Default', 'overrides' => 'app.name'],
    ]);
    $runner->central('settings-config', $actor, fn () => app(SettingManager::class)->set('branding.name', 'Central title'));
    expect(app(PlatformSettingsReader::class)->records()->pluck('value')->all())->toBe(['Central title']);
    config(['nvl-settings.overrides.enabled' => true, 'app.name' => 'Before']);
    $runtime = app(PlatformSettingsRuntime::class);
    expect(fn () => $runtime->run(function (): void {
        expect(config('app.name'))->toBe('Central title');
        throw new RuntimeException('callback failed');
    }))->toThrow(RuntimeException::class, 'callback failed');
    expect(config('app.name'))->toBe('Before');
    $runner->run(OwnershipCompany::query()->findOrFail(7), $actor, fn () => expect(fn () => app(ConfigOverrideApplier::class)->apply())->toThrow(OwnershipViolation::class));
});
