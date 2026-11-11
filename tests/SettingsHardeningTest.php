<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Events\SettingChanged;
use Nvl\Settings\Models\Setting;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingsDoctor;
use Nvl\Settings\Tests\Fixtures\InteractsWithSettings;
use Nvl\Settings\Tests\TestCase;

uses(TestCase::class, InteractsWithSettings::class);

it('inspects the platform store schema once per configuration projection', function (): void {
    $this->defineSettings([
        'branding.name' => ['type' => SettingType::Text, 'default' => 'Platform', 'overrides' => 'app.name'],
    ]);
    config()->set('nvl-settings.overrides.enabled', true);
    config()->set('app.name', 'Original');
    $queries = static function (Closure $operation): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $operation();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $inspection = $queries(static fn (): bool => app(PlatformSettingsReader::class)->available());
    $projection = $queries(static fn (): mixed => app(PlatformSettingsRuntime::class)->run(
        static fn (): mixed => expect(config('app.name'))->toBe('Platform'),
    ));

    expect($inspection)->toBeGreaterThan(0)
        ->and($projection)->toBe($inspection + 1)
        ->and(config('app.name'))->toBe('Original');
});

it('resolves unsynchronized defaults with the runtime type of a synchronized fallback', function (): void {
    $this->defineSettings([
        'catalog.tax_rate' => ['type' => SettingType::Decimal, 'default' => 0.2],
        'catalog.page_size' => ['type' => SettingType::Integer, 'default' => '24'],
        'catalog.launch_on' => ['type' => SettingType::Date, 'default' => '2026-08-02'],
    ]);
    $repository = app(SettingRepository::class);
    $read = static fn (): array => [
        $repository->get('catalog.tax_rate'),
        $repository->get('catalog.page_size'),
        $repository->get('catalog.launch_on'),
    ];

    $definitionOnly = $read();
    $record = app(SettingReader::class)->get('catalog.page_size');
    $this->artisan('nvl:settings:sync')->assertSuccessful();
    $synchronized = $read();

    expect($definitionOnly[0])->toBe('0.2')
        ->and($definitionOnly[1])->toBe(24)
        ->and($definitionOnly[2])->toBeInstanceOf(CarbonImmutable::class)
        ->and($definitionOnly[2]->format('Y-m-d'))->toBe('2026-08-02')
        ->and($record['value'])->toBe(24)
        ->and($synchronized[0])->toBe($definitionOnly[0])
        ->and($synchronized[1])->toBe($definitionOnly[1])
        ->and($synchronized[2]->equalTo($definitionOnly[2]))->toBeTrue()
        ->and(Setting::query()->where('key', 'page_size')->firstOrFail()->revision)->toBe(1);
});

it('refuses a config mapping whose target contains a denied key', function (): void {
    config()->set('services.payments', ['secret' => 'protected', 'mode' => 'live']);
    config()->set('nvl-settings.overrides.denied', ['services.payments.secret']);
    config()->set('nvl-settings.overrides.enabled', true);
    $this->defineSettings([
        'platform.payments' => [
            'type' => SettingType::Json,
            'default' => ['secret' => 'replaced', 'mode' => 'test'],
            'overrides' => 'services.payments',
        ],
        'platform.payment_mode' => [
            'type' => SettingType::Text,
            'default' => 'test',
            'overrides' => 'services.payments.mode',
        ],
    ]);

    app(ConfigOverrideApplier::class)->apply();

    expect(config('services.payments.secret'))->toBe('protected')
        ->and(config('services.payments.mode'))->toBe('test');
});

it('treats an equivalent write as a no-op when storage reorders definition metadata', function (): void {
    $this->defineSettings([
        'catalog.tax_rate' => [
            'type' => SettingType::Decimal,
            'default' => '0.20',
            'metadata' => ['unit' => 'ratio', 'group' => 'pricing', 'a' => 1],
        ],
    ]);
    $first = settingsSetForTest('catalog.tax_rate', '0.25');
    Event::fake([SettingChanged::class]);

    $second = settingsSetForTest('catalog.tax_rate', '0.25', $first['revision']);

    expect($second['revision'])->toBe($first['revision'])
        ->and(Setting::query()->firstOrFail()->metadata)->toEqualCanonicalizing(['unit' => 'ratio', 'group' => 'pricing', 'a' => 1]);
    Event::assertNotDispatched(SettingChanged::class);
});

it('stores decimal text without surrounding whitespace', function (): void {
    $this->defineSettings([
        'catalog.tax_rate' => ['type' => SettingType::Decimal, 'default' => '0.20'],
    ]);

    $stored = settingsSetForTest('catalog.tax_rate', " 0.25\n");

    expect($stored['value'])->toBe('0.25')
        ->and(Setting::query()->firstOrFail()->getRawOriginal('value'))->toBe('0.25')
        ->and(SettingType::Decimal->serialize("\t1e3 "))->toBe('1e3');
});

it('diagnoses a settings table on a prefixed connection', function (): void {
    $connection = DB::connection((new Setting)->getConnectionName());
    $table = (new Setting)->getTable();
    config()->set('nvl-settings.tables.settings', 'prefixed_settings');
    $connection->setTablePrefix('doctor_');
    $migration = require dirname(__DIR__).'/database/migrations/2026_01_01_000000_nvl_settings_create_settings_table.php';

    try {
        $migration->up();
        $checks = collect(app(SettingsDoctor::class)->inspect());

        expect($checks->firstWhere('key', 'schema.row-integrity')['passed'])->toBeTrue()
            ->and($checks->firstWhere('key', 'schema.value-codec')['passed'])->toBeTrue();

        if ($connection->getDriverName() === 'sqlite') {
            $connection->table('prefixed_settings')->insert([
                'id' => '00000000-0000-0000-0000-000000000001',
                'namespace' => 'catalog',
                'key' => str_repeat('k', 101),
                'type' => SettingType::Text->value,
                'definition_hash' => str_repeat('a', 64),
            ]);

            expect(collect(app(SettingsDoctor::class)->inspect())->firstWhere('key', 'schema.row-integrity')['passed'])->toBeFalse();
        }
    } finally {
        $migration->down();
        $connection->setTablePrefix('');
        config()->set('nvl-settings.tables.settings', $table);
    }
});
