<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Settings\Actions\GetManySettingsAction;
use Nvl\Settings\Actions\GetSettingAction;
use Nvl\Settings\Actions\ResetSettingAction;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Exceptions\InvalidDefinitionException;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Services\SettingsDoctor;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Testing\InteractsWithSettings;
use Nvl\Settings\Tests\TestCase;

uses(InteractsWithSettings::class);

it('preserves nested settings defaults around consumer overrides', function (): void {
    config()->set('settings', [
        'storage' => [
            'table' => 'consumer_settings',
        ],
    ]);

    (new SettingsServiceProvider(app()))->register();

    expect(config('settings.storage.table'))->toBe('consumer_settings')
        ->and(config('settings.storage.connection'))->toBeNull()
        ->and(config('settings.cache.key'))->toBe('nvl:settings:v2')
        ->and(config('settings.management.enabled'))->toBeFalse();
});

it('resolves defaults from fake definition', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'products.pricing.tax_inclusive' => ['type' => SettingType::Boolean, 'default' => false],
    ]);

    $repo = app(SettingRepository::class);

    expect($repo->get('products.pricing.tax_inclusive'))->toBeFalse();
});

it('discovers and validates equivalent PHP and JSON definition sources', function (): void {
    $repository = app(DefinitionRepository::class);
    $definitions = $repository->all();

    expect($repository->map())->toHaveCount(2)
        ->and($definitions)->toHaveKeys([
            'catalog.listing.page_size',
            'interface.theme',
        ])
        ->and($definitions['catalog.listing.page_size']->type)->toBe(SettingType::Integer)
        ->and($definitions['catalog.listing.page_size']->default)->toBe(24)
        ->and($repository->checksum())->toMatch('/^[a-f0-9]{64}$/');

    $this->artisan('nvl:settings:validate')->assertSuccessful()
        ->expectsOutputToContain('Validated 2 definitions from 2 source files');
    $this->artisan('nvl:settings:validate', ['--format' => 'json'])
        ->assertSuccessful()
        ->expectsOutputToContain('"valid": true');
});

it('rejects malformed JSON and defaults that violate their declared type', function (
    string $fixture,
    string $message,
): void {
    config()->set('settings.discovery.paths', [__DIR__."/Fixtures/{$fixture}"]);
    app()->forgetInstance(DefinitionRepository::class);

    expect(fn () => app(DefinitionRepository::class)->all())
        ->toThrow(InvalidDefinitionException::class, $message);
})->with([
    'malformed JSON' => ['invalid-json', 'contains invalid JSON'],
    'invalid typed default' => ['invalid-default', 'has an invalid default'],
    'invalid rule name' => ['invalid-rule', 'declares invalid validation rules'],
    'non-deterministic rule' => [
        'invalid-rule-fingerprint',
        'cannot be fingerprinted deterministically',
    ],
    'non-deterministic metadata' => [
        'invalid-metadata',
        'cannot be hashed deterministically',
    ],
    'missing explicit default' => ['missing-default', 'must declare an explicit default'],
    'unknown definition key' => ['unknown-key', 'contains unknown keys'],
    'mismatched filename namespace' => [
        'mismatched-namespace',
        'does not match filename namespace',
    ],
]);

it('enforces configured source count and byte limits before parsing', function (): void {
    config()->set('settings.discovery.maximum_files', 1);
    app()->forgetInstance(DefinitionRepository::class);

    expect(fn () => app(DefinitionRepository::class)->all())
        ->toThrow(InvalidDefinitionException::class, '1-file limit');

    config()->set('settings.discovery.maximum_files', 100);
    config()->set('settings.discovery.maximum_file_bytes', 32);
    app()->forgetInstance(DefinitionRepository::class);

    expect(fn () => app(DefinitionRepository::class)->all())
        ->toThrow(InvalidDefinitionException::class, 'exceeds 32 bytes');
});

it('returns non-zero command status before sync when a source is invalid', function (): void {
    config()->set('settings.discovery.paths', [__DIR__.'/Fixtures/invalid-default']);
    app()->forgetInstance(DefinitionRepository::class);

    $this->artisan('nvl:settings:validate')
        ->expectsOutputToContain('has an invalid default')
        ->assertFailed();
    $this->artisan('nvl:settings:sync')
        ->expectsOutputToContain('has an invalid default')
        ->assertFailed();

    expect(Setting::query()->count())->toBe(0);
});

it('can set and get settings', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'products.pricing.tax_inclusive' => ['type' => SettingType::Boolean, 'default' => false],
    ]);

    $repo = app(SettingRepository::class);

    $repo->set('products.pricing.tax_inclusive', true);

    expect($repo->get('products.pricing.tax_inclusive'))->toBeTrue();
});

it('persists definition fallbacks before an explicit sync and restores them on forget', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.currency' => ['type' => SettingType::Text, 'default' => 'EUR'],
    ]);

    $repository = app(SettingRepository::class);
    $repository->set('catalog.currency', 'BGN');
    $repository->forget('catalog.currency');

    expect($repository->get('catalog.currency'))->toBe('EUR')
        ->and(Setting::query()->firstOrFail()->fallback)->toBe('EUR');
});

it('applies only explicitly allowed config overrides', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'branding.name' => [
            'type' => SettingType::Text,
            'default' => 'Store',
            'overrides' => 'app.name',
        ],
        'security.key' => [
            'type' => SettingType::Text,
            'default' => 'unsafe',
            'overrides' => 'app.key',
        ],
    ]);

    config()->set('settings.overrides.enabled', true);
    config()->set('app.name', 'Original');
    config()->set('app.key', 'protected');

    $repository = app(SettingRepository::class);
    $repository->setMany([
        'branding.name' => 'Public Brand',
        'security.key' => 'must-not-apply',
    ]);
    app(ConfigOverrideApplier::class)->apply();

    expect(config('app.name'))->toBe('Public Brand')
        ->and(config('app.key'))->toBe('protected');
});

it('applies mapped definition defaults before settings are synchronized', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'branding.name' => [
            'type' => SettingType::Text,
            'default' => 'Definition Brand',
            'overrides' => 'app.name',
        ],
    ]);
    config()->set('settings.overrides.enabled', true);
    config()->set('app.name', 'Original');

    app(ConfigOverrideApplier::class)->apply();

    expect(config('app.name'))->toBe('Definition Brand')
        ->and(Setting::query()->count())->toBe(0);
});

it('stores nullable overrides independently from their null payload', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.optional_flag' => [
            'type' => SettingType::Boolean,
            'default' => true,
            'rules' => ['nullable'],
        ],
    ]);

    $stored = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.optional_flag',
        value: null,
        expectedRevision: 0,
    ));
    $record = Setting::query()->firstOrFail();

    expect($stored->value)->toBeNull()
        ->and($stored->source)->toBe('database')
        ->and($stored->hasOverride)->toBeTrue()
        ->and($record->has_override)->toBeTrue()
        ->and($record->getRawOriginal('value'))->toBeNull();

    $reset = app(ResetSettingAction::class)->execute(
        'catalog.optional_flag',
        $stored->revision,
    );

    expect($reset->value)->toBeTrue()
        ->and($reset->source)->toBe('definition')
        ->and($reset->hasOverride)->toBeFalse();
});

it('caches only primitive setting payloads on serialized stores', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $key = 'nvl:settings:test:'.Str::uuid();
    config()->set('settings.cache.store', 'file');
    config()->set('settings.cache.key', $key);
    config()->set('cache.serializable_classes', false);
    $repository = app(SettingRepository::class);

    try {
        $repository->set('catalog.enabled', true);

        expect($repository->get('catalog.enabled'))->toBeTrue();

        $payload = Cache::store('file')->get($key);

        expect($payload)->toBeArray()
            ->and($payload[0])->toBeArray()
            ->and($payload[0]['value'])->toBe('1')
            ->and($repository->get('catalog.enabled'))->toBeTrue();
    } finally {
        Cache::store('file')->forget($key);
    }
});

it('rebuilds malformed primitive cache payloads before model hydration', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $key = 'nvl:settings:test:'.Str::uuid();
    config()->set('settings.cache.store', 'array');
    config()->set('settings.cache.key', $key);
    $repository = app(SettingRepository::class);
    $repository->set('catalog.enabled', true);
    $repository->get('catalog.enabled');
    $malformed = Cache::store('array')->get($key);
    $malformed[0]['value'] = 1.25;
    Cache::store('array')->forever($key, $malformed);

    expect($repository->get('catalog.enabled'))->toBeTrue()
        ->and(app(SettingCache::class)->records())->toHaveCount(1)
        ->and(Cache::store('array')->get($key))->toBeArray()
        ->and(Cache::store('array')->get($key)[0])->toHaveKeys([
            'id',
            'namespace',
            'scope',
            'key',
            'type',
            'value',
            'has_override',
            'fallback',
            'metadata',
            'definition_hash',
            'revision',
        ]);
});

it('invalidates cached settings only after the outer transaction commits', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    $repository->set('catalog.enabled', true);
    expect($repository->get('catalog.enabled'))->toBeTrue();
    $key = config('settings.cache.key');
    $connection = DB::connection((new Setting)->getConnectionName());

    $connection->beginTransaction();
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();

    expect(Cache::store(config('settings.cache.store'))->has($key))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeTrue();

    $connection->rollBack();

    expect(Cache::store(config('settings.cache.store'))->has($key))->toBeTrue()
        ->and($repository->get('catalog.enabled'))->toBeTrue();

    $connection->beginTransaction();
    $setting = Setting::query()->firstOrFail();
    $setting->value = false;
    $setting->save();
    $connection->commit();

    expect(Cache::store(config('settings.cache.store'))->has($key))->toBeFalse()
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('does not emit setting changes or flush cache when an outer transaction rolls back', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    expect($repository->get('catalog.enabled'))->toBeFalse();
    Event::fake([SettingChanged::class]);
    $connection = DB::connection((new Setting)->getConnectionName());
    $connection->beginTransaction();

    app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.enabled',
        value: true,
        expectedRevision: 0,
    ));

    Event::assertNotDispatched(SettingChanged::class);
    $connection->rollBack();

    Event::assertNotDispatched(SettingChanged::class);
    expect(Setting::query()->count())->toBe(0)
        ->and($repository->get('catalog.enabled'))->toBeFalse();
});

it('emits one setting change after a committed mutation', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    Event::fake([SettingChanged::class]);

    app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.enabled',
        value: true,
        expectedRevision: 0,
    ));

    Event::assertDispatched(
        SettingChanged::class,
        static fn (SettingChanged $event): bool => $event->key === 'catalog.enabled'
            && $event->operation === 'set'
            && $event->revision === 1,
    );
});

it('treats repeated canonical action and repository writes as no-ops', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.launch_at' => [
            'type' => SettingType::DateTime,
            'default' => '2026-01-01T00:00:00+00:00',
        ],
    ]);
    $first = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.launch_at',
        value: '2026-01-01T12:30:00+02:00',
        expectedRevision: 0,
    ));
    $firstSyncedAt = Setting::query()->firstOrFail()->synced_at?->toAtomString();
    $this->travel(1)->minute();
    Event::fake([SettingChanged::class]);

    $second = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.launch_at',
        value: '2026-01-01T10:30:00+00:00',
        expectedRevision: $first->revision,
    ));
    app(SettingRepository::class)->set(
        'catalog.launch_at',
        '2026-01-01T10:30:00+00:00',
    );
    $record = Setting::query()->firstOrFail();

    expect($second->revision)->toBe($first->revision)
        ->and($record->revision)->toBe($first->revision)
        ->and($record->synced_at?->toAtomString())->toBe($firstSyncedAt);
    Event::assertNotDispatched(SettingChanged::class);
});

it('emits batch events only for settings whose persisted state changed', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.first' => ['type' => SettingType::Boolean, 'default' => false],
        'catalog.second' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    $repository->setMany([
        'catalog.first' => true,
        'catalog.second' => true,
    ]);
    Event::fake([SettingChanged::class]);

    $repository->setMany([
        'catalog.first' => true,
        'catalog.second' => false,
    ]);

    expect(Setting::query()->where('key', 'first')->firstOrFail()->revision)->toBe(1)
        ->and(Setting::query()->where('key', 'second')->firstOrFail()->revision)->toBe(2);
    Event::assertDispatchedTimes(SettingChanged::class, 1);
    Event::assertDispatched(
        SettingChanged::class,
        static fn (SettingChanged $event): bool => $event->key === 'catalog.second',
    );
});

it('resolves many settings with one storage query while preserving input order', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.first' => ['type' => SettingType::Integer, 'default' => 1],
        'catalog.second' => ['type' => SettingType::Integer, 'default' => 2],
        'catalog.third' => ['type' => SettingType::Integer, 'default' => 3],
    ]);
    app(SettingRepository::class)->set('catalog.second', 20);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $values = app(GetManySettingsAction::class)->execute([
        'catalog.third',
        'catalog.first',
        'catalog.second',
        'catalog.first',
    ]);
    $queries = array_values(array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_starts_with(
            Str::lower($query['query']),
            'select',
        ) && Str::contains($query['query'], 'settings'),
    ));

    expect(array_map(static fn ($value): string => $value->key, $values))->toBe([
        'catalog.third',
        'catalog.first',
        'catalog.second',
    ])->and(array_map(static fn ($value): mixed => $value->value, $values))->toBe([
        3,
        1,
        20,
    ])->and($queries)->toHaveCount(1);
});

it('rejects non-canonical stored scalar and temporal values', function (
    SettingType $type,
    string $raw,
): void {
    expect(fn (): mixed => $type->deserialize($raw))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'boolean text' => [SettingType::Boolean, 'true'],
    'integer with leading zero' => [SettingType::Integer, '01'],
    'relative date' => [SettingType::Date, 'tomorrow'],
    'relative date-time' => [SettingType::DateTime, 'tomorrow'],
    'invalid calendar date-time' => [
        SettingType::DateTime,
        '2026-02-30T10:00:00+00:00',
    ],
]);

it('rejects text values that are not valid UTF-8', function (): void {
    expect(fn (): ?string => SettingType::Text->serialize("\xB1\x31"))
        ->toThrow(InvalidArgumentException::class, 'valid UTF-8');
});

it('normalizes date-time overrides to UTC without inflating first-write revisions', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.launch_at' => [
            'type' => SettingType::DateTime,
            'default' => '2026-01-01T00:00:00+00:00',
        ],
    ]);

    app(SettingRepository::class)->set(
        'catalog.launch_at',
        '2026-01-01T12:30:00+02:00',
    );
    $record = Setting::query()->firstOrFail();

    expect($record->getRawOriginal('value'))->toBe('2026-01-01T10:30:00+00:00')
        ->and($record->revision)->toBe(1)
        ->and($record->value->toAtomString())->toBe('2026-01-01T10:30:00+00:00');
});

it('normalizes date-time microseconds without discarding their precision', function (): void {
    $serialized = SettingType::DateTime->serialize(
        '2026-01-01T12:30:00.123+02:00',
    );
    $deserialized = SettingType::DateTime->deserialize($serialized);

    expect($serialized)->toBe('2026-01-01T10:30:00.123000+00:00')
        ->and($deserialized->format('u'))->toBe('123000')
        ->and(SettingType::DateTime->serialize($deserialized))->toBe($serialized);
});

it('synchronizes definition fallbacks and timestamps', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.page_size' => ['type' => SettingType::Integer, 'default' => 24],
    ]);

    $this->artisan('nvl:settings:sync')->assertSuccessful();

    $setting = Setting::query()->firstOrFail();

    expect($setting->fullKey())->toBe('catalog.page_size')
        ->and($setting->resolved())->toBe(24)
        ->and($setting->synced_at)->not->toBeNull()
        ->and($setting->created_at)->not->toBeNull();
});

it('synchronizes discovered PHP and JSON sources through one validated pipeline', function (): void {
    $this->artisan('nvl:settings:sync')->assertSuccessful();

    expect(Setting::query()->count())->toBe(2)
        ->and(Setting::query()->where([
            'namespace' => 'catalog',
            'scope' => 'listing',
            'key' => 'page_size',
        ])->firstOrFail()->fallback)->toBe(24)
        ->and(Setting::query()->where([
            'namespace' => 'interface',
            'scope' => '',
            'key' => 'theme',
        ])->firstOrFail()->fallback)->toBe('light');
});

it('reports orphan pruning during a dry run without mutating records', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.active' => ['type' => SettingType::Boolean, 'default' => true],
    ]);

    Setting::query()->create([
        'namespace' => 'catalog',
        'scope' => '',
        'key' => 'removed',
        'type' => SettingType::Text,
        'value' => 'legacy',
    ]);

    $this->artisan('nvl:settings:sync', ['--dry-run' => true])
        ->expectsOutputToContain('Would orphan 1 orphaned settings')
        ->assertSuccessful();

    expect(Setting::query()->where('key', 'removed')->firstOrFail()->orphaned_at)->toBeNull();
});

it('uses UUIDs, typed values, and optimistic concurrency actions', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.tax_rate' => [
            'type' => SettingType::Decimal,
            'default' => '0.20',
            'metadata' => ['unit' => 'ratio'],
        ],
    ]);

    $initial = app(GetSettingAction::class)->execute('catalog.tax_rate');
    $stored = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.tax_rate',
        value: '0.25',
        expectedRevision: 0,
    ));
    $reset = app(ResetSettingAction::class)->execute('catalog.tax_rate', $stored->revision);

    expect($initial->source)->toBe('definition')
        ->and($stored->source)->toBe('database')
        ->and($stored->value)->toBe('0.25')
        ->and($reset->source)->toBe('definition')
        ->and(Setting::query()->firstOrFail()->getKey())->toBeUuid()
        ->and(fn () => app(SetSettingAction::class)->execute(new SettingMutationData(
            key: 'catalog.tax_rate',
            value: '0.30',
            expectedRevision: 0,
        )))->toThrow(StaleSettingVersionException::class)
        ->and(fn () => app(SetSettingAction::class)->execute(new SettingMutationData(
            key: 'catalog.tax_rate',
            value: '0.30',
            expectedRevision: $stored->revision,
        )))->toThrow(StaleSettingVersionException::class);
});

it('refreshes the discovery cache after definition files are added', function (): void {
    $filesystem = app(Filesystem::class);
    $source = sys_get_temp_dir().'/nvl-settings-'.Str::uuid();
    $cache = app()->bootstrapPath('cache/nvl-settings-'.Str::uuid().'.php');
    $filesystem->makeDirectory($source);
    $filesystem->put($source.'/catalog.settings.json', json_encode([
        'namespace' => 'catalog',
        'settings' => [
            'enabled' => ['type' => 'bool', 'default' => true],
        ],
    ], JSON_THROW_ON_ERROR));
    config()->set('settings.discovery.paths', [$source]);
    config()->set('settings.discovery.cache', true);
    config()->set('settings.discovery.cache_path', $cache);
    app()->forgetInstance(DefinitionRepository::class);

    try {
        $this->artisan('nvl:settings:cache')->assertSuccessful();
        $filesystem->put($source.'/interface.settings.json', json_encode([
            'namespace' => 'interface',
            'settings' => [
                'theme' => ['type' => 'string', 'default' => 'light'],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->artisan('nvl:settings:cache')->assertSuccessful();
        app()->forgetInstance(DefinitionRepository::class);

        expect(app(DefinitionRepository::class)->all())->toHaveKeys([
            'catalog.enabled',
            'interface.theme',
        ]);

        $filesystem->put($source.'/broken.settings.json', '{"namespace":');
        $this->artisan('nvl:settings:validate')
            ->expectsOutputToContain('contains invalid JSON')
            ->assertFailed();
    } finally {
        $filesystem->delete($cache);
        $filesystem->deleteDirectory($source);
    }
});

it('orphans a namespace removed from the discovery map', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.active' => ['type' => SettingType::Boolean, 'default' => true],
    ]);
    $this->artisan('nvl:settings:sync')->assertSuccessful();

    app(DefinitionRepository::class)->fake([]);
    $this->artisan('nvl:settings:sync')->assertSuccessful();

    expect(Setting::query()->where('key', 'active')->firstOrFail()->orphaned_at)
        ->not->toBeNull();
});

it('restores an orphaned row through the repository mutation boundary', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.active' => ['type' => SettingType::Boolean, 'default' => false],
    ]);
    $repository = app(SettingRepository::class);
    $repository->set('catalog.active', true);
    $setting = Setting::query()->firstOrFail();
    Setting::query()->whereKey($setting->getKey())->update([
        'orphaned_at' => now(),
        'revision' => $setting->revision + 1,
    ]);

    $repository->set('catalog.active', true);
    $restored = Setting::query()->firstOrFail();

    expect($restored->orphaned_at)->toBeNull()
        ->and($restored->revision)->toBe(3);
});

it('preserves live overrides and monotonic revisions while definitions change', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.label' => ['type' => SettingType::Text, 'default' => 'Original'],
    ]);
    $stored = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.label',
        value: 'Runtime',
        expectedRevision: 0,
    ));
    app(DefinitionRepository::class)->fake([
        'catalog.label' => ['type' => SettingType::Text, 'default' => 'Changed'],
    ]);

    $this->artisan('nvl:settings:sync')->assertSuccessful();
    $setting = Setting::query()->firstOrFail();

    expect($setting->value)->toBe('Runtime')
        ->and($setting->fallback)->toBe('Changed')
        ->and($setting->has_override)->toBeTrue()
        ->and($setting->revision)->toBeGreaterThan($stored->revision);
});

it('rolls back synchronization when a stored override violates a changed type', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.value' => ['type' => SettingType::Text, 'default' => 'default'],
    ]);
    app(SettingRepository::class)->set('catalog.value', 'not-an-integer');
    $before = Setting::query()->firstOrFail();
    app(DefinitionRepository::class)->fake([
        'catalog.value' => ['type' => SettingType::Integer, 'default' => 10],
    ]);

    $this->artisan('nvl:settings:sync')
        ->expectsOutputToContain('Stored override for [catalog.value] is invalid')
        ->assertFailed();
    $after = Setting::query()->firstOrFail();

    expect($after->type)->toBe(SettingType::Text)
        ->and($after->value)->toBe('not-an-integer')
        ->and($after->revision)->toBe($before->revision);
});

it('returns a failing dry-run status when a stored override violates a changed type', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.value' => ['type' => SettingType::Text, 'default' => 'default'],
    ]);
    app(SettingRepository::class)->set('catalog.value', 'not-an-integer');
    app(DefinitionRepository::class)->fake([
        'catalog.value' => ['type' => SettingType::Integer, 'default' => 10],
    ]);

    $this->artisan('nvl:settings:sync', ['--dry-run' => true])
        ->expectsOutputToContain('Stored override for [catalog.value] is invalid')
        ->assertFailed();

    expect(Setting::query()->firstOrFail()->type)->toBe(SettingType::Text);
});

it('does not reset synchronized rows that have no override', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'catalog.enabled' => ['type' => SettingType::Boolean, 'default' => true],
    ]);
    $this->artisan('nvl:settings:sync')->assertSuccessful();

    $this->artisan('nvl:settings:reset', ['pattern' => 'catalog.enabled'])
        ->expectsOutputToContain('Reset 0 settings')
        ->assertSuccessful();

    expect(Setting::query()->firstOrFail()->revision)->toBe(1)
        ->and(Setting::query()->firstOrFail()->has_override)->toBeFalse();
});

it('resets an exact two-segment setting key through the command action boundary', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'interface.theme' => ['type' => SettingType::Text, 'default' => 'light'],
    ]);
    app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'interface.theme',
        value: 'dark',
        expectedRevision: 0,
    ));

    $this->artisan('nvl:settings:reset', ['pattern' => 'interface.theme'])
        ->assertSuccessful();

    $value = app(GetSettingAction::class)->execute('interface.theme');

    expect($value->value)->toBe('light')
        ->and($value->source)->toBe('definition')
        ->and($value->revision)->toBe(2);
});

it('resolves scheduled overrides and their effective source at runtime', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'interface.theme' => ['type' => SettingType::Text, 'default' => 'light'],
    ]);
    $start = now()->addHour();
    $end = now()->addHours(2);
    $scheduled = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'interface.theme',
        value: 'dark',
        expectedRevision: 0,
        validFrom: $start->toAtomString(),
        validUntil: $end->toAtomString(),
    ));

    expect($scheduled->value)->toBe('light')
        ->and($scheduled->source)->toBe('definition');

    $this->travelTo($start->copy()->addMinute());
    $active = app(GetSettingAction::class)->execute('interface.theme');

    expect($active->value)->toBe('dark')
        ->and($active->source)->toBe('database');

    $this->travelTo($end->copy()->addMinute());
    $expired = app(GetSettingAction::class)->execute('interface.theme');

    expect($expired->value)->toBe('light')
        ->and($expired->source)->toBe('definition');
});

it('validates the effective window when validity fields are patched separately', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'interface.theme' => ['type' => SettingType::Text, 'default' => 'light'],
    ]);
    $stored = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'interface.theme',
        value: 'dark',
        expectedRevision: 0,
        validFrom: now()->addHours(2)->toAtomString(),
    ));

    expect(fn () => app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'interface.theme',
        value: 'dark',
        expectedRevision: $stored->revision,
        validUntil: now()->addHour()->toAtomString(),
    )))->toThrow(
        ValidationException::class,
        'validity end must be after',
    );
});

it('rejects scheduled windows for boot-time configuration overrides', function (): void {
    /** @var TestCase $this */
    config()->set('app.name', 'Original');
    $this->defineSettings([
        'branding.name' => [
            'type' => SettingType::Text,
            'default' => 'Brand',
            'overrides' => 'app.name',
        ],
    ]);

    expect(fn () => app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'branding.name',
        value: 'Scheduled Brand',
        expectedRevision: 0,
        validFrom: now()->addHour()->toAtomString(),
    )))->toThrow(
        ValidationException::class,
        'cannot use scheduled validity windows',
    );
});

it('rejects sub-second validity windows that storage cannot preserve', function (): void {
    /** @var TestCase $this */
    $this->defineSettings([
        'interface.theme' => ['type' => SettingType::Text, 'default' => 'light'],
    ]);

    expect(fn () => app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'interface.theme',
        value: 'dark',
        expectedRevision: 0,
        validFrom: '2026-01-01T10:30:00.123+00:00',
    )))->toThrow(
        ValidationException::class,
        'whole-second precision',
    );
});

it('rejects setting identity segments that exceed storage limits', function (): void {
    $segment = str_repeat('a', 101);

    expect(fn () => app(DefinitionRepository::class)->fake([
        "{$segment}.key" => [
            'type' => SettingType::Text,
            'default' => 'value',
        ],
    ]))->toThrow(InvalidDefinitionException::class, 'valid namespace');
});

it('requires exact index uniqueness when diagnosing a consumer table', function (): void {
    config()->set('settings.storage.table', 'consumer_settings');
    $migration = require __DIR__.'/../database/migrations/2026_01_01_000000_create_settings_table.php';
    $migration->up();

    try {
        Schema::table('consumer_settings', function (Blueprint $table): void {
            $table->dropIndex(['namespace', 'scope']);
        });
        Schema::table('consumer_settings', function (Blueprint $table): void {
            $table->unique(['namespace', 'scope']);
        });
        $check = collect(app(SettingsDoctor::class)->inspect())
            ->firstWhere('key', 'schema.index.namespace-scope');

        expect($check->passed)->toBeFalse();
    } finally {
        $migration->down();
    }
});

it('continues identifier diagnostics after encountering an invalid value codec', function (): void {
    $now = now();
    DB::table((new Setting)->getTable())->insert([
        [
            'id' => '00000000-0000-0000-0000-000000000001',
            'namespace' => 'catalog',
            'scope' => '',
            'key' => 'invalid_codec',
            'type' => SettingType::Boolean->value,
            'value' => null,
            'has_override' => false,
            'fallback' => 'true',
            'metadata' => null,
            'definition_hash' => str_repeat('a', 64),
            'revision' => 1,
            'valid_from' => null,
            'valid_until' => null,
            'synced_at' => null,
            'orphaned_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'namespace' => 'bad namespace',
            'scope' => '',
            'key' => 'unsafe_identifier',
            'type' => SettingType::Text->value,
            'value' => null,
            'has_override' => false,
            'fallback' => 'safe',
            'metadata' => null,
            'definition_hash' => str_repeat('b', 64),
            'revision' => 1,
            'valid_from' => null,
            'valid_until' => null,
            'synced_at' => null,
            'orphaned_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);
    $checks = collect(app(SettingsDoctor::class)->inspect());

    expect($checks->firstWhere('key', 'schema.value-codec')->passed)->toBeFalse()
        ->and($checks->firstWhere('key', 'schema.identifiers')->passed)->toBeFalse();
});

it('validates configured-table indexes by columns instead of hardcoded names', function (): void {
    config()->set('settings.storage.table', 'consumer_settings');
    $migration = require __DIR__.'/../database/migrations/2026_01_01_000000_create_settings_table.php';
    $migration->up();

    try {
        $checks = collect(app(SettingsDoctor::class)->inspect());

        expect($checks
            ->filter(static fn ($check): bool => str_starts_with(
                $check->key,
                'schema.index.',
            ))
            ->every(static fn ($check): bool => $check->passed))->toBeTrue();
    } finally {
        $migration->down();
    }
});

it('reports a healthy clean-install schema', function (): void {
    expect(collect(app(SettingsDoctor::class)->inspect())->every(
        static fn ($check): bool => $check->passed,
    ))->toBeTrue();

    $this->artisan('nvl:settings:doctor', ['--strict' => true, '--format' => 'json'])
        ->assertSuccessful();
});
