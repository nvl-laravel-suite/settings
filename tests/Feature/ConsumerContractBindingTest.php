<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingWriter;
use Nvl\Settings\Tests\TestCase;
use Nvl\Support\Enums\SystemActor;

uses(TestCase::class);

test('native settings capabilities expose typed operations without payload DTOs', function (): void {
    $reader = new ReflectionClass(SettingReader::class);
    $writer = new ReflectionClass(SettingWriter::class);

    expect(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $reader->getMethods(ReflectionMethod::IS_PUBLIC)))
        ->toContain('get', 'getMany', 'paginate')
        ->and(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $writer->getMethods(ReflectionMethod::IS_PUBLIC)))
        ->toContain('set', 'reset', 'sync')
        ->and((string) $writer->getMethod('set')->getParameters()[2]->getType())
        ->toContain('Authenticatable', 'SystemActor')
        ->and((string) $writer->getMethod('set')->getReturnType())->toBe('array');
});

test('native provider resolves reader writer and read-only runtime repository', function (): void {
    expect($this->app->make(SettingReader::class))->toBeInstanceOf(SettingReader::class)
        ->and($this->app->make(SettingWriter::class))->toBeInstanceOf(SettingWriter::class)
        ->and($this->app->make(SettingRepository::class))->toBeInstanceOf(SettingRepository::class)
        ->and(method_exists($this->app->make(SettingRepository::class), 'set'))->toBeFalse();
});

test('provider registration preserves host authorization bindings in a second application', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $authorization = new class implements SettingsAuthorization
    {
        public function authorize(Authenticatable|SystemActor $actor, SettingAbility $ability, ?string $key = null): void {}
    };
    $consumer->instance(SettingsAuthorization::class, $authorization);

    try {
        $consumer->register(SettingsServiceProvider::class);
        expect($consumer->make(SettingsAuthorization::class))->toBe($authorization);
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
