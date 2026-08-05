<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nvl\Settings\Enums\SettingType;
use Nvl\Settings\Support\DefinitionRepository;

test('management routes are disabled by default', function (): void {
    expect(Route::has('nvl.settings.management.index'))->toBeFalse();
});

test('enabled management routes authorize typed reads and optimistic writes', function (): void {
    config()->set('settings.management.enabled', true);
    config()->set('settings.management.path', 'api/internal/runtime-settings');
    config()->set('settings.management.name', 'runtime.settings');
    config()->set('settings.management.middleware', []);
    config()->set('settings.management.authorization_ability', 'manage-settings');
    Gate::define(
        'manage-settings',
        static fn (?Authenticatable $actor, string $ability, ?string $key): bool => in_array(
            $ability,
            ['status', 'list', 'view', 'set', 'reset'],
            true,
        ),
    );
    require __DIR__.'/../routes/api.php';
    app('router')->getRoutes()->refreshNameLookups();

    expect(Route::has('runtime.settings.status'))->toBeTrue()
        ->and(Route::has('runtime.settings.index'))->toBeTrue()
        ->and(Route::has('nvl.settings.management.index'))->toBeFalse();

    app(DefinitionRepository::class)->fake([
        'interface.theme' => [
            'type' => SettingType::Text,
            'default' => 'light',
        ],
    ]);

    $this->getJson('/api/internal/runtime-settings/status')
        ->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.definitionCount', 1);

    $this->getJson('/api/internal/runtime-settings')
        ->assertOk()
        ->assertJsonPath('data.items.0.value.value', 'light')
        ->assertJsonPath('data.items.0.value.source', 'definition')
        ->assertJsonPath('data.meta.total', 1);

    $this->putJson('/api/internal/runtime-settings/interface.theme', [
        'value' => 'dark',
        'expectedRevision' => 0,
    ])
        ->assertOk()
        ->assertJsonPath('data.value', 'dark')
        ->assertJsonPath('data.revision', 1);

    $this->deleteJson('/api/internal/runtime-settings/interface.theme', [
        'expectedRevision' => 1,
    ])
        ->assertOk()
        ->assertJsonPath('data.value', 'light')
        ->assertJsonPath('data.source', 'definition')
        ->assertJsonPath('data.revision', 2);
});

test('management listing remains bounded with large definition sets', function (): void {
    config()->set('settings.management.enabled', true);
    config()->set('settings.management.path', 'api/internal/runtime-settings');
    config()->set('settings.management.name', 'runtime.settings');
    config()->set('settings.management.middleware', []);
    config()->set('settings.management.authorization_ability', 'manage-settings');
    Gate::define(
        'manage-settings',
        static fn (?Authenticatable $actor, string $ability): bool => $ability === 'list',
    );
    require __DIR__.'/../routes/api.php';

    $definitions = [];

    foreach (range(1, 501) as $number) {
        $definitions[sprintf('catalog.item_%03d', $number)] = [
            'type' => SettingType::Integer,
            'default' => $number,
        ];
    }

    app(DefinitionRepository::class)->fake($definitions);

    $this->getJson('/api/internal/runtime-settings?perPage=25&page=2')
        ->assertOk()
        ->assertJsonCount(25, 'data.items')
        ->assertJsonPath('data.meta.currentPage', 2)
        ->assertJsonPath('data.meta.perPage', 25)
        ->assertJsonPath('data.meta.total', 501);
});

test('management routes return stable unknown and stale error contracts', function (): void {
    config()->set('settings.management.enabled', true);
    config()->set('settings.management.path', 'api/internal/runtime-settings');
    config()->set('settings.management.name', 'runtime.settings');
    config()->set('settings.management.middleware', []);
    config()->set('settings.management.authorization_ability', 'manage-settings');
    Gate::define(
        'manage-settings',
        static fn (?Authenticatable $actor, string $ability): bool => in_array(
            $ability,
            ['view', 'set', 'reset'],
            true,
        ),
    );
    require __DIR__.'/../routes/api.php';
    app(DefinitionRepository::class)->fake([
        'interface.theme' => [
            'type' => SettingType::Text,
            'default' => 'light',
        ],
        'interface.locale' => [
            'type' => SettingType::Text,
            'default' => 'en',
        ],
    ]);

    $this->getJson('/api/internal/runtime-settings/interface.missing')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'unknown_setting');

    $this->putJson('/api/internal/runtime-settings/interface.theme', [
        'value' => 'dark',
        'expectedRevision' => 0,
    ])->assertOk();

    $this->putJson('/api/internal/runtime-settings/interface.theme', [
        'value' => 'light',
        'expectedRevision' => 0,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'stale_setting_revision');

    $this->deleteJson('/api/internal/runtime-settings/interface.locale', [
        'expectedRevision' => 1,
    ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'setting_override_not_found');
});

test('management writes require an explicit value and optimistic revision', function (): void {
    config()->set('settings.management.enabled', true);
    config()->set('settings.management.path', 'api/internal/runtime-settings');
    config()->set('settings.management.name', 'runtime.settings');
    config()->set('settings.management.middleware', []);
    config()->set('settings.management.authorization_ability', 'manage-settings');
    Gate::define(
        'manage-settings',
        static fn (?Authenticatable $actor, string $ability): bool => $ability === 'set',
    );
    require __DIR__.'/../routes/api.php';
    app(DefinitionRepository::class)->fake([
        'interface.theme' => [
            'type' => SettingType::Text,
            'default' => 'light',
        ],
    ]);

    $this->putJson('/api/internal/runtime-settings/interface.theme', [
        'expectedRevision' => 0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('value');

    $this->putJson('/api/internal/runtime-settings/interface.theme', [
        'value' => 'dark',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('expectedRevision');
});
