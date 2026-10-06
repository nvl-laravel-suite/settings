<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Settings\Tests\PlatformSettingsBootTestCase;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Symfony\Component\Console\Output\BufferedOutput;

uses(PlatformSettingsBootTestCase::class);

/** Fails immediately if a native command boot tries to read override storage. */
final class SettingsNativeBootDatabaseProbeProvider extends ServiceProvider
{
    public static int $attempts = 0;

    /** Observe queries in both the original and config-cache fresh application. */
    public function register(): void
    {
        $this->app->make('db')->beforeExecuting(static function (): never {
            self::$attempts++;
            throw new LogicException('Native command boot attempted Settings database I/O.');
        });
    }
}

test('native discovery and configuration cache never read enabled override storage', function (bool $unavailable): void {
    app(SettingRepository::class)->set('branding.name', 'Database-only branding sentinel');
    $files = new Filesystem;
    $path = sys_get_temp_dir().'/nvl-settings-native-boot-'.Str::uuid();
    foreach (['app', 'bootstrap/cache', 'config', 'storage/framework/cache/data', 'storage/framework/views', 'storage/logs'] as $directory) {
        $files->makeDirectory($path.'/'.$directory, recursive: true);
    }
    $providers = [
        SettingsNativeBootDatabaseProbeProvider::class,
        SupportServiceProvider::class,
        DataServiceProvider::class,
        TenancyServiceProvider::class,
        SettingsServiceProvider::class,
    ];
    $files->put($path.'/bootstrap/app.php', '<?php return \\Illuminate\\Foundation\\Application::configure(basePath: '.var_export($path, true).')->withProviders('.var_export($providers, true).', withBootstrapProviders: false)->create();');
    $configuration = [
        'app' => ['name' => 'Original platform', 'env' => 'testing', 'key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='],
        'database' => [
            'default' => 'sqlite',
            'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $unavailable ? $path.'/missing/database.sqlite' : $this->databasePath, 'prefix' => '']],
        ],
        'nvl-settings' => [
            'discovery' => ['paths' => [__DIR__.'/../Fixtures/platform-settings'], 'cache' => false],
            'cache' => ['enabled' => false],
            'overrides' => ['enabled' => true],
        ],
        'nvl-tenancy' => ['enabled' => true, 'migrations' => ['enabled' => false]],
    ];
    foreach ($configuration as $name => $values) {
        $files->put($path.'/config/'.$name.'.php', '<?php return '.var_export($values, true).';');
    }
    SettingsNativeBootDatabaseProbeProvider::$attempts = 0;

    try {
        /** @var Application $consumer */
        $consumer = require $path.'/bootstrap/app.php';
        $kernel = $consumer->make(Kernel::class);
        expect($kernel->call('package:discover', [], new BufferedOutput))->toBe(0)
            ->and($kernel->call('config:cache', [], new BufferedOutput))->toBe(0)
            ->and(SettingsNativeBootDatabaseProbeProvider::$attempts)->toBe(0);
        $cached = require $consumer->getCachedConfigPath();
        expect($cached['app']['name'])->toBe('Original platform')
            ->and($files->get($consumer->getCachedConfigPath()))->not->toContain('Database-only branding sentinel');
        expect(fn () => $consumer->make(PlatformSettingsRuntime::class)->run(static fn (): null => null))
            ->toThrow(LogicException::class, 'Native command boot attempted Settings database I/O.');
        expect(SettingsNativeBootDatabaseProbeProvider::$attempts)->toBe(1);
    } finally {
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
        $files->deleteDirectory($path);
    }
})->with([true, false]);
