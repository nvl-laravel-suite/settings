<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Settings\Actions\ListSettingsAction;
use Nvl\Settings\Actions\SetSettingAction;
use Nvl\Settings\Data\SettingListQueryData;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Exceptions\InvalidDefinitionException;
use Nvl\Settings\Exceptions\StaleSettingVersionException;
use Nvl\Settings\Services\SettingsDoctor;
use Nvl\Settings\Support\DefinitionFileLoader;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\SettingsRouteConfiguration;
use Nvl\Settings\Testing\InteractsWithSettings;

uses(InteractsWithSettings::class);

it('enforces every public setting codec boundary', function (): void {
    $date = new DateTimeImmutable('2026-08-02T12:30:00+03:00');

    expect(SettingType::Boolean->serialize(null))->toBeNull()
        ->and(SettingType::Boolean->serialize(false))->toBe('0')
        ->and(SettingType::Integer->serialize('42'))->toBe('42')
        ->and(SettingType::Decimal->serialize(42))->toBe('42')
        ->and(SettingType::Decimal->serialize(1.25))->toBe('1.25')
        ->and(SettingType::Date->serialize($date))->toBe('2026-08-02')
        ->and(SettingType::DateTime->serialize($date))->toBe('2026-08-02T09:30:00+00:00')
        ->and(SettingType::Json->deserialize('{"enabled":true}'))->toBe([
            'enabled' => true,
        ])
        ->and(SettingType::Date->deserialize('2026-08-02')->format('Y-m-d'))
        ->toBe('2026-08-02')
        ->and(fn () => SettingType::Boolean->serialize('true'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Integer->serialize('1.5'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Decimal->serialize(INF))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Text->serialize(42))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Json->serialize('object'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Date->serialize(42))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Date->serialize('02/08/2026'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Date->serialize('2026-02-30'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::DateTime->serialize(42))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Boolean->deserialize('yes'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Integer->deserialize('042'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::Json->deserialize('true'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::DateTime->deserialize('2026-08-02 12:30:00'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SettingType::DateTime->deserialize('2026-99-99T12:30:00+99:99'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects unsafe definition files and invalid source document shapes', function (): void {
    $filesystem = app(Filesystem::class);
    $directory = sys_get_temp_dir().'/nvl-settings-consumer-'.Str::uuid();
    $filesystem->makeDirectory($directory);
    $loader = app(DefinitionFileLoader::class);

    try {
        expect(fn () => $loader->load($directory.'/missing.settings.json'))
            ->toThrow(InvalidDefinitionException::class, 'missing or unreadable');

        $filesystem->put($directory.'/unsupported.txt', 'unsupported');
        $filesystem->put($directory.'/list.settings.json', '[]');
        $filesystem->put($directory.'/scalar.settings.json', 'true');
        $filesystem->put(
            $directory.'/numeric.settings.php',
            '<?php return [1 => "invalid", "namespace" => "numeric"];',
        );
        $filesystem->put(
            $directory.'/throws.settings.php',
            '<?php throw new RuntimeException("consumer failure");',
        );

        expect(fn () => $loader->load($directory.'/unsupported.txt'))
            ->toThrow(InvalidDefinitionException::class, 'Unsupported')
            ->and(fn () => $loader->load($directory.'/list.settings.json'))
            ->toThrow(InvalidDefinitionException::class, 'must contain an object')
            ->and(fn () => $loader->load($directory.'/scalar.settings.json'))
            ->toThrow(InvalidDefinitionException::class, 'JSON object')
            ->and(fn () => $loader->load($directory.'/numeric.settings.php'))
            ->toThrow(InvalidDefinitionException::class, 'string keys')
            ->and(fn () => $loader->load($directory.'/throws.settings.php'))
            ->toThrow(InvalidDefinitionException::class, 'could not be loaded');

        $invalidDocuments = [
            [['unexpected' => true], 'contains unknown keys'],
            [['namespace' => []], 'invalid namespace'],
            [['namespace' => 'probe', 'scopes' => 'invalid'], 'scopes must be an array'],
            [[
                'namespace' => 'probe',
                'settings' => [],
                'scopes' => ['' => []],
            ], 'both settings and an empty scope'],
            [['namespace' => 'probe', 'settings' => 'invalid'], 'settings must be an array'],
            [['namespace' => 'probe', 'scopes' => ['bad.scope' => []]], 'invalid scope'],
            [['namespace' => 'probe', 'scopes' => ['valid' => 'invalid']], 'must be an array'],
            [['namespace' => 'probe', 'settings' => ['key' => 'invalid']], 'invalid key definition'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'string', 'default' => '', 'rules' => 'invalid'],
            ]], 'rules must be an array'],
            [['namespace' => 'probe', 'settings' => [
                'key' => [
                    'type' => 'string',
                    'default' => '',
                    'rules' => ['rule' => 'nullable'],
                ],
            ]], 'rules must be a list'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'string', 'default' => '', 'rules' => [42]],
            ]], 'string validation rules'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'string', 'default' => '', 'metadata' => 'invalid'],
            ]], 'metadata must be an object'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'string', 'default' => '', 'overrides' => ''],
            ]], 'non-empty config key'],
            [['namespace' => 'probe', 'settings' => [
                'key' => [
                    'type' => 'string',
                    'default' => '',
                    'overrides' => 'missing.consumer.config',
                ],
            ]], 'targets unknown config key'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'string', 'default' => '', 'description' => []],
            ]], 'invalid metadata'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'string', 'default' => '', 'metadata' => [0 => 'bad']],
            ]], 'metadata keys must be strings'],
            [['namespace' => 'probe', 'settings' => [
                'key' => ['type' => 'unsupported', 'default' => ''],
            ]], 'valid SettingType'],
        ];

        config()->set('settings.discovery.paths', [$directory]);
        config()->set('settings.discovery.cache', false);
        config()->set('settings.discovery.patterns', ['probe.settings.json']);

        foreach ($invalidDocuments as [$document, $message]) {
            $filesystem->put(
                $directory.'/probe.settings.json',
                json_encode($document, JSON_THROW_ON_ERROR),
            );
            app()->forgetInstance(DefinitionRepository::class);

            expect(fn () => app(DefinitionRepository::class)->all())
                ->toThrow(InvalidDefinitionException::class, $message);
        }
    } finally {
        $filesystem->deleteDirectory($directory);
    }
});

it('validates discovery cache and management adoption failures', function (): void {
    $filesystem = app(Filesystem::class);
    $directory = sys_get_temp_dir().'/nvl-settings-doctor-'.Str::uuid();
    $cache = app()->bootstrapPath('cache/nvl-settings-'.Str::uuid().'.php');
    $filesystem->makeDirectory($directory);
    $filesystem->put($directory.'/probe.settings.json', json_encode([
        'namespace' => 'probe',
        'settings' => [
            'enabled' => ['type' => 'bool', 'default' => true],
        ],
    ], JSON_THROW_ON_ERROR));
    config()->set([
        'settings.discovery.paths' => [$directory],
        'settings.discovery.cache' => true,
        'settings.discovery.cache_path' => $cache,
    ]);

    try {
        $filesystem->put($cache, '<?php return "invalid";');
        app()->forgetInstance(DefinitionRepository::class);
        expect(fn () => app(DefinitionRepository::class)->map())
            ->toThrow(InvalidDefinitionException::class, 'must return an array');

        $filesystem->put($cache, '<?php return ["bad.name" => ""];');
        app()->forgetInstance(DefinitionRepository::class);
        expect(fn () => app(DefinitionRepository::class)->map())
            ->toThrow(InvalidDefinitionException::class, 'invalid source');

        $filesystem->put($cache, '<?php return [];');
        app()->forgetInstance(DefinitionRepository::class);
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['definitions.cache']->passed)->toBeFalse()
            ->and($checks['definitions.cache']->message)->toContain('stale');

        config()->set('settings.discovery.cache_path', ['/invalid']);
        app()->forgetInstance(DefinitionRepository::class);
        expect(fn () => app(DefinitionRepository::class)->cachePath())
            ->toThrow(InvalidDefinitionException::class, 'absolute path');

        config()->set('settings.discovery.cache_path', '/tmp/outside-settings-cache.php');
        app()->forgetInstance(DefinitionRepository::class);
        expect(fn () => app(DefinitionRepository::class)->cachePath())
            ->toThrow(InvalidDefinitionException::class, 'remain below');

        config()->set([
            'settings.discovery.cache' => false,
            'settings.storage.table' => 'missing_consumer_settings',
        ]);
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['schema.table']->passed)->toBeFalse();

        config()->set('settings.storage.table', 'incomplete_consumer_settings');
        Schema::create('incomplete_consumer_settings', function ($table): void {
            $table->uuid('id')->primary();
        });
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['schema.columns']->passed)->toBeFalse();
        Schema::drop('incomplete_consumer_settings');

        config()->set([
            'settings.storage.table' => 'settings',
            'settings.management.enabled' => true,
            'settings.management.path' => '../unsafe',
        ]);
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['management.routes']->passed)->toBeFalse();

        config()->set([
            'settings.management.path' => 'api/consumer/settings',
            'settings.management.name' => 'consumer.settings',
            'settings.management.middleware' => ['api'],
            'settings.management.authorization_ability' => null,
        ]);
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['management.routes']->message)->toContain('authorization');

        config()->set([
            'settings.management.authorization_ability' => 'manage-settings',
            'settings.management.middleware' => [],
        ]);
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['management.routes']->message)->toContain('middleware');

        config()->set('settings.management.middleware', ['api']);
        $checks = collect(app(SettingsDoctor::class)->inspect())->keyBy('key');
        expect($checks['management.routes']->message)->toContain('not registered');

        config()->set('settings.management.path', 42);
        expect(fn () => SettingsRouteConfiguration::path())
            ->toThrow(InvalidArgumentException::class, 'must be a string');
        config()->set('settings.management.path', '');
        expect(fn () => SettingsRouteConfiguration::path())
            ->toThrow(InvalidArgumentException::class, 'safe');
        config()->set('settings.management.name', 42);
        expect(fn () => SettingsRouteConfiguration::name())
            ->toThrow(InvalidArgumentException::class, 'must be a string');
        config()->set('settings.management.name', 'bad name');
        expect(fn () => SettingsRouteConfiguration::name())
            ->toThrow(InvalidArgumentException::class, 'safe');
    } finally {
        $filesystem->delete($cache);
        $filesystem->deleteDirectory($directory);
        Schema::dropIfExists('incomplete_consumer_settings');
    }
});

it('exercises filtered management reads and operational commands', function (): void {
    $this->defineSettings([
        'catalog.listing.enabled' => [
            'type' => SettingType::Boolean,
            'default' => false,
            'description' => 'Needle feature',
            'position' => 2,
        ],
        'catalog.payload' => [
            'type' => SettingType::Json,
            'default' => ['mode' => 'default'],
            'position' => 1,
        ],
        'catalog.optional' => [
            'type' => SettingType::Text,
            'default' => null,
            'rules' => ['nullable'],
        ],
        'interface.label' => [
            'type' => SettingType::Text,
            'default' => str_repeat('label-', 12),
        ],
    ]);
    $enabled = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.listing.enabled',
        value: true,
        expectedRevision: 0,
    ));
    app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'catalog.payload',
        value: ['mode' => 'consumer'],
        expectedRevision: 0,
    ));

    $page = app(ListSettingsAction::class)->execute(new SettingListQueryData(
        namespace: 'catalog',
        scope: 'listing',
        search: 'needle',
        page: 1,
        perPage: 10,
    ));

    expect($page->meta->total)->toBe(1)
        ->and($page->items[0]['value']['revision'])->toBe($enabled->revision);

    $this->artisan('nvl:settings:list')->assertSuccessful();
    $this->artisan('nvl:settings:list', [
        '--namespace' => 'catalog',
        '--changed' => true,
    ])->assertSuccessful();

    config()->set('settings.sync.prune', []);
    $this->artisan('nvl:settings:sync')
        ->expectsOutputToContain('Unsupported settings prune strategy')
        ->assertFailed();
    config()->set('settings.sync.prune', 'orphan');

    $this->artisan('nvl:settings:sync', ['--provider' => 'catalog'])
        ->assertSuccessful();
    app(DefinitionRepository::class)->fake([]);
    $this->artisan('nvl:settings:sync', ['--prune' => true])
        ->assertSuccessful();
});

it('covers optimistic action updates and stale first writes', function (): void {
    $this->defineSettings([
        'branding.name' => [
            'type' => SettingType::Text,
            'default' => 'Default',
            'overrides' => 'app.name',
        ],
        'catalog.unwritten' => [
            'type' => SettingType::Integer,
            'default' => 10,
        ],
    ]);
    $first = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'branding.name',
        value: 'First',
        expectedRevision: 0,
    ));
    $second = app(SetSettingAction::class)->execute(new SettingMutationData(
        key: 'branding.name',
        value: 'Second',
        expectedRevision: $first->revision,
    ));

    expect($second->value)->toBe('Second')
        ->and($second->revision)->toBeGreaterThan($first->revision)
        ->and(fn () => app(SetSettingAction::class)->execute(new SettingMutationData(
            key: 'catalog.unwritten',
            value: 20,
            expectedRevision: 2,
        )))->toThrow(StaleSettingVersionException::class);
});
