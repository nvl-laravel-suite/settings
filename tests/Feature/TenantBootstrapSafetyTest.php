<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

uses(InteractsWithSettings::class);

function settingsContextFor(OwnershipContextMode $mode): OwnershipContext
{
    return new class($mode) implements OwnershipContext
    {
        public function __construct(private OwnershipContextMode $mode) {}

        public function snapshot(): OwnershipSnapshot
        {
            if ($this->mode === OwnershipContextMode::Owner) {
                $owner = new OwnerReference('company', 'companies', 'int', '7');

                return new OwnershipSnapshot($this->mode, $owner, ['kind' => 'actor', 'type' => 'users', 'id' => '1']);
            }

            return new OwnershipSnapshot($this->mode);
        }

        public function requireOwner(): OwnerReference
        {
            return $this->snapshot()->owner ?? throw new OwnershipViolation('No owner is admitted.');
        }
    };
}

test('owner runtime cannot apply process configuration overrides', function (): void {
    app()->instance(OwnershipContext::class, settingsContextFor(OwnershipContextMode::Owner));
    config()->set('nvl-settings.overrides.enabled', true);
    $before = config()->all();

    expect(fn () => app(ConfigOverrideApplier::class)->apply())->toThrow(OwnershipViolation::class);
    expect(config()->all())->toBe($before);
});

test('owner runtime remains denied when configuration overrides are switched off', function (): void {
    app()->instance(OwnershipContext::class, settingsContextFor(OwnershipContextMode::Owner));
    config()->set('nvl-settings.overrides.enabled', false);

    expect(fn () => app(ConfigOverrideApplier::class)->apply())->toThrow(OwnershipViolation::class);
});

test('unresolved runtime rejects configuration overrides before settings storage is read', function (): void {
    app()->instance(OwnershipContext::class, settingsContextFor(OwnershipContextMode::Unresolved));
    config()->set('nvl-settings.overrides.enabled', true);
    $before = config()->all();
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(fn () => app(ConfigOverrideApplier::class)->apply())->toThrow(OwnershipViolation::class);
    expect(config()->all())->toBe($before)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('disabled ownership keeps mapped defaults and persisted overrides without ownership tables', function (): void {
    $this->defineSettings([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Platform name', 'overrides' => 'app.name'],
    ]);
    config()->set('nvl-settings.overrides.enabled', true);
    config()->set('app.name', 'Original');
    app(ConfigOverrideApplier::class)->apply();
    expect(config('app.name'))->toBe('Platform name');

    settingsSetForTest('branding.name', 'Persisted platform');
    app(ConfigOverrideApplier::class)->apply();
    expect(config('app.name'))->toBe('Persisted platform')
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Disabled)
        ->and(Schema::hasTable('nvl_ownership_installations'))->toBeFalse();
});

test('platform reader returns only records with explicit configuration mappings', function (): void {
    $this->defineSettings([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Platform name', 'overrides' => 'app.name'],
        'branding.internal' => ['type' => SettingType::Text, 'default' => 'Internal'],
    ]);
    settingsSetForTest('branding.name', 'Persisted platform');
    settingsSetForTest('branding.internal', 'Private platform value');

    expect(app(PlatformSettingsReader::class)->records()->map->fullKey()->all())->toBe(['branding.name']);
});

test('runtime override services receive fresh context dependencies after scope reset', function (): void {
    $first = app(ConfigOverrideApplier::class);
    app()->forgetScopedInstances();

    expect(app(ConfigOverrideApplier::class))->not->toBe($first);
});
