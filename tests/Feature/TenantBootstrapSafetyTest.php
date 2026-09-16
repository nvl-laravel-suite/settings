<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Testing\InteractsWithSettings;
use Nvl\Settings\Tests\TestCase;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;

uses(InteractsWithSettings::class);

test('tenant runtime cannot apply process configuration overrides', function (): void {
    /** @var TestCase $this */
    $tenant = new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $this->app->instance(TenantContext::class, new class($tenant) implements TenantContext
    {
        public function __construct(private TenantId $id) {}

        public function snapshot(): TenantContextSnapshot
        {
            return new TenantContextSnapshot(TenantContextMode::Tenant, $this->id);
        }

        public function requireTenant(): TenantId
        {
            return $this->id;
        }
    });
    config()->set('settings.overrides.enabled', true);
    $before = config()->all();

    expect(fn () => app(ConfigOverrideApplier::class)->apply())
        ->toThrow(TenantBoundaryViolation::class);
    expect(config()->all())->toBe($before);
});

test('tenant runtime remains denied when configuration overrides are switched off', function (): void {
    /** @var TestCase $this */
    $tenant = new TenantId('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    $this->app->instance(TenantContext::class, new class($tenant) implements TenantContext
    {
        public function __construct(private TenantId $id) {}

        public function snapshot(): TenantContextSnapshot
        {
            return new TenantContextSnapshot(TenantContextMode::Tenant, $this->id);
        }

        public function requireTenant(): TenantId
        {
            return $this->id;
        }
    });
    config()->set('settings.overrides.enabled', false);

    expect(fn () => app(ConfigOverrideApplier::class)->apply())
        ->toThrow(TenantBoundaryViolation::class);
});

test('unresolved runtime rejects configuration overrides before settings storage is read', function (): void {
    /** @var TestCase $this */
    $this->app->instance(TenantContext::class, new class implements TenantContext
    {
        public function snapshot(): TenantContextSnapshot
        {
            return new TenantContextSnapshot(TenantContextMode::Unresolved);
        }

        public function requireTenant(): TenantId
        {
            throw new TenantContextMissing('Tenant context is not resolved.');
        }
    });
    config()->set('settings.overrides.enabled', true);
    $before = config()->all();
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(fn () => app(ConfigOverrideApplier::class)->apply())
        ->toThrow(TenantBoundaryViolation::class);
    expect(config()->all())->toBe($before)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('disabled tenancy keeps mapped defaults and persisted overrides without tenancy tables', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'branding.name' => [
            'type' => SettingType::Text,
            'default' => 'Platform name',
            'overrides' => 'app.name',
        ],
    ]);
    config()->set('settings.overrides.enabled', true);
    config()->set('app.name', 'Original');

    app(ConfigOverrideApplier::class)->apply();

    expect(config('app.name'))->toBe('Platform name');

    app(SettingRepository::class)->set('branding.name', 'Persisted platform');
    app(ConfigOverrideApplier::class)->apply();

    expect(config('app.name'))->toBe('Persisted platform')
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled)
        ->and(Schema::hasTable('nvl_tenancy_installation_state'))->toBeFalse();
});

test('platform reader returns only records with explicit configuration mappings', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'branding.name' => [
            'type' => SettingType::Text,
            'default' => 'Platform name',
            'overrides' => 'app.name',
        ],
        'branding.internal' => [
            'type' => SettingType::Text,
            'default' => 'Internal',
        ],
    ]);
    app(SettingRepository::class)->setMany([
        'branding.name' => 'Persisted platform',
        'branding.internal' => 'Private platform value',
    ]);

    expect(app(PlatformSettingsReader::class)->records()->map->fullKey()->all())
        ->toBe(['branding.name']);
});

test('runtime override services receive fresh context dependencies after scope reset', function (): void {
    $first = app(ConfigOverrideApplier::class);

    app()->forgetScopedInstances();

    expect(app(ConfigOverrideApplier::class))->not->toBe($first);
});
