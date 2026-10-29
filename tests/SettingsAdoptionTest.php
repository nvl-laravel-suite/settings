<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingsAdopter;
use Nvl\Settings\Services\SettingsDoctor;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Settings\Tests\SettingsCacheTestCase;
use Nvl\Support\Enums\SystemActor;

uses(InteractsWithSettings::class);

it('bounds source reads when a staged import exceeds its declared row count', function (): void {
    $this->defineSettings(['core.enabled' => ['type' => SettingType::Boolean, 'default' => false]]);
    Schema::create('oversized_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
    });
    DB::table('oversized_settings')->insert([
        ['key' => 'first', 'value' => '1'],
        ['key' => 'second', 'value' => '1'],
        ['key' => 'third', 'value' => '1'],
    ]);
    DB::enableQueryLog();
    try {
        expect(fn () => app(SettingsAdopter::class)->execute([
            'version' => 1,
            'source_table' => 'oversized_settings',
            'expected_count' => 1,
            'key_replacements' => ['first' => 'core.enabled'],
        ]))->toThrow(InvalidArgumentException::class);
        $query = collect(DB::getQueryLog())->first(fn (array $entry): bool => str_contains($entry['query'], 'from "oversized_settings"'));
        expect($query['query'])->toContain('limit 2')
            ->and(Setting::query()->count())->toBe(0);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('plans applies and reconciles an explicit legacy key replacement manifest', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'core.dual_pricing_enabled' => [
            'type' => SettingType::Boolean,
            'default' => false,
        ],
        'notifications.reminder_minutes' => [
            'type' => SettingType::Json,
            'default' => [15],
            'rules' => ['settings_integer_list_between:1,60'],
        ],
    ]);
    Schema::create('legacy_settings', function (Blueprint $table): void {
        $table->string('legacy_key')->primary();
        $table->text('legacy_value')->nullable();
    });
    DB::table('legacy_settings')->insert([
        [
            'legacy_key' => 'core.currency.dual_pricing.enabled',
            'legacy_value' => '1',
        ],
        [
            'legacy_key' => 'notifications.reminders.minutes',
            'legacy_value' => '[5,15]',
        ],
    ]);
    $manifest = [
        'version' => 1,
        'source_table' => 'legacy_settings',
        'key_column' => 'legacy_key',
        'value_column' => 'legacy_value',
        'expected_count' => 2,
        'key_replacements' => [
            'core.currency.dual_pricing.enabled' => 'core.dual_pricing_enabled',
            'notifications.reminders.minutes' => 'notifications.reminder_minutes',
        ],
        'expected_revisions' => ['core.dual_pricing_enabled' => 0, 'notifications.reminder_minutes' => 0],
    ];
    $action = app(SettingsAdopter::class);

    $plan = $action->execute($manifest);

    expect($plan['mode'])->toBe('plan')
        ->and($plan['reconciliation'])->toBe([
            'expected' => 2,
            'source' => 2,
            'mapped' => 2,
            'matched' => 0,
            'created' => 2,
            'updated' => 0,
            'unchanged' => 0,
        ])
        ->and(Setting::query()->count())->toBe(0);

    $applied = $action->execute($manifest, apply: true, actor: settingsTestActor());

    expect($applied['reconciliation']['matched'])->toBe(2)
        ->and(Setting::query()->where('key', 'dual_pricing_enabled')->firstOrFail()->value)->toBeTrue()
        ->and(Setting::query()->where('key', 'reminder_minutes')->firstOrFail()->value)->toBe([5, 15]);

    $manifest['expected_revisions'] = ['core.dual_pricing_enabled' => 1, 'notifications.reminder_minutes' => 1];
    $repeated = $action->execute($manifest, apply: true, actor: settingsTestActor());

    expect($repeated['reconciliation']['created'])->toBe(0)
        ->and($repeated['reconciliation']['updated'])->toBe(0)
        ->and($repeated['reconciliation']['unchanged'])->toBe(2)
        ->and($repeated['reconciliation']['matched'])->toBe(2);
});

it('runs the adoption command as a non-mutating dry run by default', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'core.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    Schema::create('legacy_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
    });
    DB::table('legacy_settings')->insert(['key' => 'core.flags.enabled', 'value' => '1']);
    $filesystem = app(Filesystem::class);
    $path = storage_path('framework/testing/settings-adoption-'.Str::uuid().'.json');
    $filesystem->put($path, json_encode([
        'version' => 1,
        'source_table' => 'legacy_settings',
        'expected_count' => 1,
        'key_replacements' => ['core.flags.enabled' => 'core.enabled'],
        'expected_revisions' => ['core.enabled' => 0],
    ], JSON_THROW_ON_ERROR));

    app()->instance(SettingsAuthorization::class, new class implements SettingsAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, SettingAbility $ability, ?string $key = null): void
        {
            if ($actor !== SystemActor::Background || $ability !== SettingAbility::Set) {
                throw new AuthorizationException;
            }
        }
    });

    try {
        $this->artisan('nvl:settings:adopt', ['manifest' => $path])
            ->expectsOutputToContain('Settings adoption plan is valid; no data was changed.')
            ->assertSuccessful();
        expect(Setting::query()->count())->toBe(0);

        $this->artisan('nvl:settings:adopt', [
            'manifest' => $path,
            '--apply' => true,
            '--format' => 'json',
        ])->expectsOutputToContain('"matched": 1')
            ->assertSuccessful();
        expect(Setting::query()->firstOrFail()->value)->toBeTrue();
    } finally {
        $filesystem->delete($path);
    }
});

it('rolls back every staged write when a later target has a stale expected revision', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'core.first' => ['type' => SettingType::Boolean, 'default' => false],
        'core.second' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    Schema::create('legacy_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
    });
    DB::table('legacy_settings')->insert([
        ['key' => 'first.old', 'value' => '1'],
        ['key' => 'second.old', 'value' => '1'],
    ]);
    settingsSetForTest('core.second', false, 0);

    $manifest = [
        'version' => 1,
        'source_table' => 'legacy_settings',
        'expected_count' => 2,
        'key_replacements' => ['first.old' => 'core.first', 'second.old' => 'core.second'],
        'expected_revisions' => ['core.first' => 0, 'core.second' => 0],
    ];

    expect(fn () => app(SettingsAdopter::class)->execute($manifest, apply: true, actor: settingsTestActor()))
        ->toThrow(function (Exception $exception): void {
            expect($exception)->toBeInstanceOf(StaleSettingVersionException::class, $exception->getMessage());
        })
        ->and(Setting::query()->count())->toBe(1)
        ->and(Setting::query()->firstOrFail()->key)->toBe('second')
        ->and(Setting::query()->firstOrFail()->revision)->toBe(1);
});

it('fails loudly for incomplete maps counts and same-name legacy collisions', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'core.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    Schema::create('legacy_settings', function (Blueprint $table): void {
        $table->string('key');
        $table->text('value');
    });
    DB::table('legacy_settings')->insert(['key' => 'unknown.legacy.key', 'value' => '1']);
    $action = app(SettingsAdopter::class);

    expect(fn () => $action->execute([
        'version' => 1,
        'source_table' => 'legacy_settings',
        'expected_count' => 1,
        'key_replacements' => ['different.legacy.key' => 'core.enabled'],
    ]))->toThrow(InvalidArgumentException::class, 'no explicit replacement')
        ->and(fn () => $action->execute([
            'version' => 1,
            'source_table' => 'legacy_settings',
            'expected_count' => 2,
            'key_replacements' => ['unknown.legacy.key' => 'core.enabled'],
        ]))->toThrow(InvalidArgumentException::class, 'expected_count')
        ->and(fn () => $action->execute([
            'version' => 1,
            'source_table' => SettingsTables::Settings,
            'expected_count' => 1,
            'key_replacements' => ['legacy.core.enabled' => 'core.enabled'],
        ]))->toThrow(InvalidArgumentException::class, 'collides');
});

it('distinguishes a same-name legacy table from the canonical package schema', function (): void {
    config()->set('nvl-settings.tables.settings', 'legacy_named_settings');
    Schema::create('legacy_named_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });

    $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');

    expect($checks['schema.compatibility']['passed'])->toBeFalse()
        ->and($checks['schema.compatibility']['message'])->toContain('legacy name collision');
});

it('captures replaceable actor and request context without setting values', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->defineSettings([
        'core.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    app()->instance(SettingsAuditContextProvider::class, new class implements SettingsAuditContextProvider
    {
        public function metadata(): array
        {
            return ['request_id' => 'request-1', 'ip_address' => '127.0.0.1', 'user_agent' => 'Settings test'];
        }
    });
    Event::fake([SettingChanged::class]);

    settingsSetForTest(
        key: 'core.enabled',
        value: true,
        expectedRevision: 0,
    );

    Event::assertDispatched(
        SettingChanged::class,
        static fn (SettingChanged $event): bool => $event->context->actorType === GenericUser::class
            && $event->context->actorId === 'user-1'
            && $event->context->requestId === 'request-1'
            && ! property_exists($event, 'value'),
    );
});

it('validates typed JSON lists and maps from portable source rules', function (): void {
    $filesystem = app(Filesystem::class);
    $directory = sys_get_temp_dir().'/nvl-settings-rules-'.Str::uuid();
    $filesystem->makeDirectory($directory);
    $filesystem->put($directory.'/structured.settings.json', json_encode([
        'namespace' => 'structured',
        'settings' => [
            'schedule' => [
                'type' => 'json',
                'default' => [5, 15],
                'rules' => ['settings_integer_list_between:1,60'],
            ],
            'limits' => [
                'type' => 'json',
                'default' => ['small' => 10, 'large' => 20],
                'rules' => ['settings_integer_map_between:1,100'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    config()->set([
        'nvl-settings.discovery.paths' => [$directory],
        'nvl-settings.discovery.cache' => false,
    ]);
    app()->forgetInstance(DefinitionRepository::class);

    try {
        $definitions = app(DefinitionRepository::class)->all();
        expect($definitions)->toHaveKeys(['structured.schedule', 'structured.limits']);

        $repository = app(SettingRepository::class);
        expect(fn () => settingsSetForTest('structured.schedule', [0, 15]))
            ->toThrow(ValidationException::class)
            ->and(fn () => settingsSetForTest('structured.limits', ['small' => '10']))
            ->toThrow(ValidationException::class);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});
