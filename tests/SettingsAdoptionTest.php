<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
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
use Nvl\Support\Adoption\AdoptionProgress;
use Nvl\Support\Adoption\AdoptionStore;
use Nvl\Support\Enums\SystemActor;

uses(InteractsWithSettings::class);

it('adopts scoped JSON values with original IDs and reports explicitly retained orphans', function (): void {
    $this->defineSettings([
        'legacy.catalog.label' => ['type' => SettingType::Text, 'default' => 'Default'],
        'legacy.checkout.flag' => ['type' => SettingType::Boolean, 'default' => true],
        'legacy.checkout.nullable' => ['type' => SettingType::Text, 'default' => 'Default', 'rules' => ['nullable']],
        'legacy.catalog.options' => ['type' => SettingType::Json, 'default' => []],
    ]);
    Schema::create('legacy_scoped_settings', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('scope');
        $table->string('key');
        $table->json('value')->nullable();
        $table->timestamps();
        $table->unique(['scope', 'key']);
    });
    $time = '2026-04-20 10:11:12';
    $values = [
        ['catalog', 'shared', '"false"'], ['checkout', 'shared', 'false'],
        ['checkout', 'nullable', 'null'], ['catalog', 'options', '{"enabled":true}'],
        ['catalog', 'orphan', '0'],
    ];
    $records = array_map(static fn (array $row): array => [
        'id' => (string) Str::uuid(), 'scope' => $row[0], 'key' => $row[1],
        'value' => $row[2], 'created_at' => $time, 'updated_at' => $time,
    ], $values);
    DB::table('legacy_scoped_settings')->insert($records);
    $manifest = [
        'version' => 1, 'source_table' => 'legacy_scoped_settings', 'scope_column' => 'scope',
        'value_encoding' => 'json', 'orphan_policy' => 'report', 'preserve_identity' => true, 'expected_count' => 5,
        'key_replacements' => [
            'catalog' => ['shared' => 'legacy.catalog.label', 'options' => 'legacy.catalog.options'],
            'checkout' => ['shared' => 'legacy.checkout.flag', 'nullable' => 'legacy.checkout.nullable'],
        ],
        'expected_revisions' => [
            'legacy.catalog.label' => 0, 'legacy.catalog.options' => 0,
            'legacy.checkout.flag' => 0, 'legacy.checkout.nullable' => 0,
        ],
    ];
    $adopter = app(SettingsAdopter::class);
    $plan = $adopter->execute($manifest);
    expect($plan['orphans'])->toBe([['scope' => 'catalog', 'key' => 'orphan']])
        ->and($plan['schema_fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and(fn () => $adopter->execute($manifest, apply: true, actor: settingsTestActor()))->toThrow(InvalidArgumentException::class, 'fingerprint');
    $manifest['fingerprint'] = $plan['fingerprint'];

    $interrupted = false;
    DB::connection()->beforeExecuting(static function (string $sql, array $bindings) use (&$interrupted, $records): void {
        if (! $interrupted && str_starts_with($sql, 'insert') && in_array($records[2]['id'], $bindings, true)) {
            $interrupted = true;
            throw new RuntimeException('simulated interruption');
        }
    });
    expect(fn () => $adopter->execute($manifest, apply: true, actor: settingsTestActor()))->toThrow(RuntimeException::class, 'simulated interruption')
        ->and(Setting::query()->count())->toBe(0);

    expect($adopter->execute($manifest, apply: true, actor: settingsTestActor())['reconciliation']['matched'])->toBe(4)
        ->and($adopter->execute($manifest, apply: true, actor: settingsTestActor())['reconciliation']['unchanged'])->toBe(4)
        ->and($adopter->verify($manifest)['reconciliation']['matched'])->toBe(4);
    $reader = app(SettingRepository::class);
    expect($reader->get('legacy.catalog.label'))->toBe('false')
        ->and($reader->get('legacy.checkout.flag'))->toBeFalse()
        ->and($reader->get('legacy.checkout.nullable'))->toBeNull()
        ->and($reader->get('legacy.catalog.options'))->toBe(['enabled' => true])
        ->and(Setting::query()->findOrFail($records[0]['id'])->updated_at?->format('Y-m-d H:i:s'))->toBe($time);

    DB::table('legacy_scoped_settings')->where('id', $records[0]['id'])->update(['value' => '"changed"']);
    expect(fn () => $adopter->verify($manifest))->toThrow(InvalidArgumentException::class, 'fingerprint');
    Schema::table('legacy_scoped_settings', static fn (Blueprint $table) => $table->dropColumn('value'));
    expect(fn () => $adopter->verify($manifest))->toThrow(InvalidArgumentException::class, 'incompatible schema');
});

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
        $source = 'from '.DB::connection()->getQueryGrammar()->wrapTable('oversized_settings');
        $query = collect(DB::getQueryLog())->first(fn (array $entry): bool => str_contains($entry['query'], $source));
        expect($query)->not->toBeNull()
            ->and($query['query'])->toContain('limit 2')
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

/** @return array<string, mixed> */
function settingsAdoptCommand(array $arguments): array
{
    $status = Artisan::call('nvl:adopt', ['package' => 'settings', ...$arguments, '--format' => 'json']);

    return ['status' => $status, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('resumes checkpointed nvl:adopt batches after a failure without duplicate revisions or events', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->artisan('migrate', ['--path' => __DIR__.'/../../core/support/database/migrations', '--realpath' => true])->assertSuccessful();
    $this->defineSettings([
        'core.first' => ['type' => SettingType::Boolean, 'default' => false],
        'core.second' => ['type' => SettingType::Text, 'default' => 'none'],
        'core.third' => ['type' => SettingType::Boolean, 'default' => true],
    ]);
    app()->instance(SettingsAuthorization::class, new class implements SettingsAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, SettingAbility $ability, ?string $key = null): void
        {
            if ($actor !== SystemActor::Background) {
                throw new AuthorizationException;
            }
        }
    });
    Schema::create('legacy_batched_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
    });
    DB::table('legacy_batched_settings')->insert([
        ['key' => 'a.first', 'value' => '1'], ['key' => 'b.second', 'value' => 'staged'], ['key' => 'c.third', 'value' => '0'],
    ]);
    $filesystem = app(Filesystem::class);
    $path = storage_path('framework/testing/settings-batched-adoption-'.Str::uuid().'.json');
    $filesystem->ensureDirectoryExists(dirname($path));
    $filesystem->put($path, json_encode([
        'version' => 1, 'source_table' => 'legacy_batched_settings', 'expected_count' => 3,
        'key_replacements' => ['a.first' => 'core.first', 'b.second' => 'core.second', 'c.third' => 'core.third'],
        'expected_revisions' => ['core.first' => 0, 'core.second' => 0, 'core.third' => 0],
    ], JSON_THROW_ON_ERROR));
    Event::fake([SettingChanged::class]);

    try {
        $plan = settingsAdoptCommand(['phase' => 'plan', 'manifest' => $path]);
        expect($plan['status'])->toBe(0)
            ->and($plan['collisions'])->toBe([])
            ->and($plan['counts']['overrides']['expected'])->toBe(3)
            ->and(Setting::query()->count())->toBe(0);

        $interrupted = false;
        DB::connection()->beforeExecuting(static function (string $sql, array $bindings) use (&$interrupted): void {
            if (! $interrupted && str_starts_with($sql, 'insert') && in_array('second', $bindings, true)) {
                $interrupted = true;
                throw new RuntimeException('simulated batch failure');
            }
        });
        $failed = settingsAdoptCommand(['phase' => 'apply', 'manifest' => $path, '--fingerprint' => $plan['fingerprint'], '--batch-size' => 1]);
        $run = (string) DB::table(AdoptionStore::Runs)->value('id');

        expect($failed['status'])->toBe(1)
            ->and($failed['error'])->toContain('simulated batch failure')
            ->and(Setting::query()->pluck('key')->all())->toBe(['first'])
            ->and(Setting::query()->firstOrFail()->revision)->toBe(1)
            ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Failed)
            ->and((int) DB::table(AdoptionStore::Checkpoints)->value('processed'))->toBe(1)
            ->and(DB::table(AdoptionStore::Checkpoints)->value('output_revision'))->toMatch('/^[a-f0-9]{64}$/');
        Event::assertDispatchedTimes(SettingChanged::class, 1);

        $resumed = settingsAdoptCommand(['phase' => 'apply', 'manifest' => $path, '--fingerprint' => $plan['fingerprint'], '--run' => $run, '--batch-size' => 1]);
        expect($resumed['status'])->toBe(0)
            ->and($resumed['state'])->toBe(AdoptionProgress::Applied)
            ->and($resumed['counts']['overrides'])->toBe(['expected' => 3, 'processed' => 3, 'complete' => true])
            ->and(Setting::query()->orderBy('key')->pluck('revision', 'key')->all())->toBe(['first' => 1, 'second' => 1, 'third' => 1]);
        expect(Event::dispatched(SettingChanged::class)->map(static fn (array $arguments): string => $arguments[0]->key)->sort()->values()->all())
            ->toBe(['core.first', 'core.second', 'core.third']);

        expect(settingsAdoptCommand(['phase' => 'verify', 'manifest' => $path])['verification']['passed'])->toBeTrue()
            ->and(settingsAdoptCommand(['phase' => 'activate', 'manifest' => $path, '--fingerprint' => $plan['fingerprint']])['state'])->toBe(AdoptionProgress::Active)
            ->and(app(SettingRepository::class)->get('core.second'))->toBe('staged');
    } finally {
        $filesystem->delete($path);
    }
});

it('refuses nvl:adopt plans whose expected revisions name unmapped targets', function (): void {
    /** @var SettingsCacheTestCase $this */
    $this->artisan('migrate', ['--path' => __DIR__.'/../../core/support/database/migrations', '--realpath' => true])->assertSuccessful();
    $this->defineSettings([
        'core.first' => ['type' => SettingType::Boolean, 'default' => false],
        'core.second' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    app()->instance(SettingsAuthorization::class, new class implements SettingsAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, SettingAbility $ability, ?string $key = null): void {}
    });
    Schema::create('legacy_unmapped_settings', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->text('value');
    });
    DB::table('legacy_unmapped_settings')->insert(['key' => 'first.old', 'value' => '1']);
    $filesystem = app(Filesystem::class);
    $path = storage_path('framework/testing/settings-unmapped-adoption-'.Str::uuid().'.json');
    $filesystem->ensureDirectoryExists(dirname($path));
    $filesystem->put($path, json_encode([
        'version' => 1, 'source_table' => 'legacy_unmapped_settings', 'expected_count' => 1,
        'key_replacements' => ['first.old' => 'core.first'],
        'expected_revisions' => ['core.first' => 0, 'core.second' => 0],
    ], JSON_THROW_ON_ERROR));

    try {
        $plan = settingsAdoptCommand(['phase' => 'plan', 'manifest' => $path]);

        expect($plan['status'])->toBe(1)
            ->and($plan['error'])->toContain('expected_revisions must contain mapped target keys')
            ->and(DB::table(AdoptionStore::Runs)->count())->toBe(0)
            ->and(Setting::query()->count())->toBe(0);
    } finally {
        $filesystem->delete($path);
    }
});
