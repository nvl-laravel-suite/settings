<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\PlatformSettingsBootstrap;
use Nvl\Settings\Tests\PlatformSettingsBootTestCase;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;

uses(PlatformSettingsBootTestCase::class);

test('enabled provider boot applies persisted platform overrides without resolving tenant context', function (): void {
    app(SettingRepository::class)->set('branding.name', 'Persisted platform');

    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(config('app.name'))->toBe('Persisted platform')
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

test('enabled provider boot applies mapped defaults from a present empty platform store', function (): void {
    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(config('app.name'))->toBe('Platform name');
});

test('enabled provider boot leaves configuration unchanged when the platform store is absent', function (): void {
    Schema::drop((new Setting)->getTable());

    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(config('app.name'))->toBe('Original platform');
});

test('enabled provider boot rejects a partial settings ownership schema', function (string $column): void {
    Schema::table((new Setting)->getTable(), function (Blueprint $table) use ($column): void {
        $table->string($column)->nullable();
    });
    $this->enableBootstrap = true;

    try {
        expect(fn () => $this->refreshApplication())
            ->toThrow(TenantSchemaNotReady::class);
    } finally {
        $this->enableBootstrap = false;
        $this->refreshApplication();
    }
})->with([
    'ownership discriminator only' => 'ownership_key',
    'tenant identifier only' => 'tenant_id',
]);

test('enabled provider boot propagates the original settings connection failure', function (): void {
    $availableDatabasePath = $this->databasePath;
    $this->enableBootstrap = true;
    $this->databasePath = sys_get_temp_dir().'/settings-boot-missing-'.Str::uuid().'/database.sqlite';

    try {
        expect(fn () => $this->refreshApplication())
            ->toThrow(QueryException::class);
    } finally {
        $this->databasePath = $availableDatabasePath;
        $this->enableBootstrap = false;
        $this->refreshApplication();
    }
});

test('platform settings bootstrap rejects direct calls after application boot', function (): void {
    expect(fn () => app(PlatformSettingsBootstrap::class)->apply())
        ->toThrow(TenantBoundaryViolation::class, 'Platform configuration bootstrap has ended.');
});
