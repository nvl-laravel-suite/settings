<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\PlatformSettingsBootstrap;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Settings\Tests\PlatformSettingsBootTestCase;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\TenantGlobalJobRegistry;

uses(PlatformSettingsBootTestCase::class);

/** Exercises real synchronous job boundaries, including nested dispatch and exceptions. */
final class PlatformSettingsLifecycleProbeJob implements ShouldQueue
{
    /** @var list<string> */
    public static array $names = [];

    /** Capture which lifecycle path this job will exercise. */
    public function __construct(private readonly bool $nested = false, private readonly bool $fail = false) {}

    /** Observe the runtime projection before and after a nested job. */
    public function handle(): void
    {
        self::$names[] = config('app.name');
        if ($this->nested) {
            Queue::push(new self);
            self::$names[] = config('app.name');
        }
        if ($this->fail) {
            throw new RuntimeException('Settings job failure.');
        }
    }
}

test('enabled provider boot defers persisted overrides until runtime and restores request configuration', function (): void {
    app(SettingRepository::class)->set('branding.name', 'Persisted platform');

    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(config('app.name'))->toBe('Original platform')
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
    Route::get('/runtime-name', static fn (): array => ['name' => config('app.name')]);
    $this->getJson('/runtime-name')->assertOk()->assertJson(['name' => 'Persisted platform']);
    expect(config('app.name'))->toBe('Original platform');
});

test('enabled runtime applies mapped defaults from a present empty platform store', function (): void {
    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(config('app.name'))->toBe('Original platform')
        ->and(app(PlatformSettingsRuntime::class)->run(static fn (): string => config('app.name')))->toBe('Platform name')
        ->and(config('app.name'))->toBe('Original platform');
});

test('enabled provider boot leaves configuration unchanged when the platform store is absent', function (): void {
    Schema::drop((new Setting)->getTable());

    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(config('app.name'))->toBe('Original platform');
    expect(app(PlatformSettingsRuntime::class)->run(static fn (): string => config('app.name')))->toBe('Original platform');
});

test('enabled runtime rejects a partial settings ownership schema after a safe provider boot', function (string $column): void {
    Schema::table((new Setting)->getTable(), function (Blueprint $table) use ($column): void {
        $table->string($column)->nullable();
    });
    $this->enableBootstrap = true;

    try {
        $this->refreshApplication();
        expect(fn () => app(PlatformSettingsRuntime::class)->run(static fn (): null => null))
            ->toThrow(TenantSchemaNotReady::class);
    } finally {
        $this->enableBootstrap = false;
        $this->refreshApplication();
    }
})->with([
    'ownership discriminator only' => 'ownership_key',
    'tenant identifier only' => 'tenant_id',
]);

test('enabled provider boot succeeds without storage and runtime propagates the connection failure', function (): void {
    $availableDatabasePath = $this->databasePath;
    $this->enableBootstrap = true;
    $this->databasePath = config('database.default') === 'sqlite'
        ? sys_get_temp_dir().'/settings-boot-missing-'.Str::uuid().'/database.sqlite'
        : 'nvl_settings_missing_'.str_replace('-', '', (string) Str::uuid());

    try {
        $this->refreshApplication();
        expect(config('app.name'))->toBe('Original platform');
        expect(fn () => app(PlatformSettingsRuntime::class)->run(static fn (): null => null))
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

test('enabled runtime restores configuration between jobs and after nested or failed sync dispatch', function (): void {
    app(SettingRepository::class)->set('branding.name', 'Persisted platform');
    $this->enableBootstrap = true;
    $this->refreshApplication();
    config()->set('queue.default', 'sync');
    app(TenantGlobalJobRegistry::class)->register(PlatformSettingsLifecycleProbeJob::class);
    PlatformSettingsLifecycleProbeJob::$names = [];

    Queue::push(new PlatformSettingsLifecycleProbeJob(nested: true));
    expect(PlatformSettingsLifecycleProbeJob::$names)->toBe(['Persisted platform', 'Persisted platform', 'Persisted platform'])
        ->and(config('app.name'))->toBe('Original platform');
    expect(fn () => Queue::push(new PlatformSettingsLifecycleProbeJob(fail: true)))
        ->toThrow(RuntimeException::class, 'Settings job failure.');
    expect(config('app.name'))->toBe('Original platform');

    app()->forgetScopedInstances();
    Queue::push(new PlatformSettingsLifecycleProbeJob);
    expect(config('app.name'))->toBe('Original platform')
        ->and(PlatformSettingsLifecycleProbeJob::$names)->toBe(['Persisted platform', 'Persisted platform', 'Persisted platform', 'Persisted platform', 'Persisted platform']);
});

test('explicit runtime restores configuration when request work throws', function (): void {
    app(SettingRepository::class)->set('branding.name', 'Persisted platform');
    $this->enableBootstrap = true;
    $this->refreshApplication();

    expect(fn () => app(PlatformSettingsRuntime::class)->run(static function (): never {
        expect(config('app.name'))->toBe('Persisted platform');
        throw new RuntimeException('Settings request failure.');
    }))->toThrow(RuntimeException::class, 'Settings request failure.');
    expect(config('app.name'))->toBe('Original platform');
});

test('a quarantined sync job cannot restore its enclosing request projection', function (): void {
    app(SettingRepository::class)->set('branding.name', 'Persisted platform');
    $this->enableBootstrap = true;
    $this->enableTenancy = false;
    $this->refreshApplication();
    config()->set('queue.default', 'sync');
    config()->set('queue.failed.driver', null);
    app()->forgetInstance('queue.failer');
    Queue::createPayloadUsing(static fn (?string $connection, ?string $queue, array $payload): array => [
        'data' => array_merge($payload['data'], ['nvl_tenancy' => null]),
    ]);

    try {
        app(PlatformSettingsRuntime::class)->run(static function (): void {
            expect(fn () => Queue::push(new PlatformSettingsLifecycleProbeJob))->toThrow(TenantConfigurationInvalid::class);
            expect(config('app.name'))->toBe('Persisted platform');
        });
        expect(config('app.name'))->toBe('Original platform');
    } finally {
        Queue::createPayloadUsing(null);
    }
});
