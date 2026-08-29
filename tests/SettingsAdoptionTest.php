<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Settings\Actions\AdoptSettingsAction;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Data\SettingAuditContextData;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\SettingsDoctor;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Testing\InteractsWithSettings;
use Nvl\Settings\Tests\TestCase;

uses(InteractsWithSettings::class);

it('plans applies and reconciles an explicit legacy key replacement manifest', function (): void {
    /** @var TestCase $this */
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
    ];
    $action = app(AdoptSettingsAction::class);

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

    $applied = $action->execute($manifest, apply: true);

    expect($applied['reconciliation']['matched'])->toBe(2)
        ->and(Setting::query()->where('key', 'dual_pricing_enabled')->firstOrFail()->value)->toBeTrue()
        ->and(Setting::query()->where('key', 'reminder_minutes')->firstOrFail()->value)->toBe([5, 15]);

    $repeated = $action->execute($manifest, apply: true);

    expect($repeated['reconciliation']['created'])->toBe(0)
        ->and($repeated['reconciliation']['updated'])->toBe(0)
        ->and($repeated['reconciliation']['unchanged'])->toBe(2)
        ->and($repeated['reconciliation']['matched'])->toBe(2);
});

it('runs the adoption command as a non-mutating dry run by default', function (): void {
    /** @var TestCase $this */
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
    ], JSON_THROW_ON_ERROR));

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

it('fails loudly for incomplete maps counts and same-name legacy collisions', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'core.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    Schema::create('legacy_settings', function (Blueprint $table): void {
        $table->string('key');
        $table->text('value');
    });
    DB::table('legacy_settings')->insert(['key' => 'unknown.legacy.key', 'value' => '1']);
    $action = app(AdoptSettingsAction::class);

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
            'source_table' => 'settings',
            'expected_count' => 1,
            'key_replacements' => ['legacy.core.enabled' => 'core.enabled'],
        ]))->toThrow(InvalidArgumentException::class, 'collides');
});

it('distinguishes a same-name legacy table from the canonical package schema', function (): void {
    config()->set('settings.storage.table', 'legacy_named_settings');
    Schema::create('legacy_named_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });

    $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');

    expect($checks['schema.compatibility']->passed)->toBeFalse()
        ->and($checks['schema.compatibility']->message)->toContain('legacy name collision');
});

it('captures replaceable actor and request context without setting values', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'core.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    app()->instance(SettingsAuditContextProvider::class, new class implements SettingsAuditContextProvider
    {
        public function current(): SettingAuditContextData
        {
            return new SettingAuditContextData(
                actorType: 'users',
                actorId: 'actor-1',
                requestId: 'request-1',
                ipAddress: '127.0.0.1',
                userAgent: 'Settings test',
            );
        }
    });
    Event::fake([SettingChanged::class]);

    app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'core.enabled',
        value: true,
        expectedRevision: 0,
    ));

    Event::assertDispatched(
        SettingChanged::class,
        static fn (SettingChanged $event): bool => $event->context->actorType === 'users'
            && $event->context->actorId === 'actor-1'
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
        'settings.discovery.paths' => [$directory],
        'settings.discovery.cache' => false,
    ]);
    app()->forgetInstance(DefinitionRepository::class);

    try {
        $definitions = app(DefinitionRepository::class)->all();
        expect($definitions)->toHaveKeys(['structured.schedule', 'structured.limits']);

        $repository = app(SettingRepository::class);
        expect(fn () => $repository->set('structured.schedule', [0, 15]))
            ->toThrow(ValidationException::class)
            ->and(fn () => $repository->set('structured.limits', ['small' => '10']))
            ->toThrow(ValidationException::class);
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});
