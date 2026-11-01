<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Settings\Providers\SettingsServiceProvider;
use Nvl\Settings\Tests\Fixtures\OwnershipActor;
use Nvl\Settings\Tests\Fixtures\OwnershipCompany;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;

/** Exercises committed ownership and cache behavior without NVL Tenancy or Auth providers. */
abstract class OwnershipTestCase extends TestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LocaleServiceProvider::class, SupportServiceProvider::class, OwnershipServiceProvider::class, SettingsServiceProvider::class];
    }

    /** Configure owned package roots before immutable resource registration. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'nvl-core.owners' => [OwnershipCompany::class, OwnershipActor::class],
            'nvl-ownership.enabled' => true, 'nvl-ownership.migrations.enabled' => false,
            'nvl-ownership.central_operations' => ['settings-config'],
            'nvl-settings.discovery.paths' => [__DIR__.'/Fixtures/settings'],
            'nvl-settings.discovery.cache' => false,
            'nvl-settings.management.authorization_ability' => 'manage-settings',
        ]);
        if (in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
            $database = getenv('NVL_OWNERSHIP_TEST_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('Native integration tests require a dedicated nvl_own_test_ database.');
            }
            $driver = (string) getenv('NVL_OWNERSHIP_TEST_DRIVER');
            $host = getenv('NVL_OWNERSHIP_TEST_HOST') ?: ($driver === 'pgsql' ? '/tmp' : '127.0.0.1');
            $port = getenv('NVL_OWNERSHIP_TEST_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306');
            if (! in_array($host, ['127.0.0.1', 'localhost', '::1', ...($driver === 'pgsql' ? ['/tmp'] : [])], true) || ! ctype_digit($port)) {
                throw new \RuntimeException('Native ownership fixtures require a loopback service and explicit numeric port.');
            }
            $connection = ['driver' => $driver, 'url' => null, 'host' => $host, 'port' => $port, 'database' => $database, 'username' => getenv('NVL_OWNERSHIP_TEST_USERNAME') ?: ($driver === 'pgsql' ? get_current_user() : 'root'), 'password' => getenv('NVL_OWNERSHIP_TEST_PASSWORD') ?: '', 'prefix' => '', 'timezone' => $driver === 'pgsql' ? 'UTC' : '+00:00'];
            $connection += $driver === 'pgsql' ? ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'] : ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin', 'strict' => true, 'engine' => 'InnoDB'];
            $app['config']->set('database.connections.sqlite', $connection);
        }
    }

    /** Install real package storage and activate through the reviewed coordinator. */
    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::getSchemaBuilder();
        if (in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
            $this->assertSame(getenv('NVL_OWNERSHIP_TEST_DRIVER'), DB::connection()->getDriverName());
            if (DB::connection()->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_DATABASE')) {
                throw new \RuntimeException('The actual native source differs from the dedicated test database.');
            }
            $schema->dropAllTables();
        }
        foreach (['settings_test_companies', 'settings_test_actors'] as $table) {
            $schema->create($table, static fn (Blueprint $table) => $table->unsignedBigInteger('id')->primary());
        }
        OwnershipCompany::query()->insert([['id' => 7], ['id' => 8]]);
        OwnershipActor::query()->create(['id' => 1]);
        Gate::define('manage-settings', static fn (OwnershipActor $actor): bool => $actor->getKey() === 1);
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
        {
            public function assertCanEnter(Authenticatable $actor, Model $owner): void {}
        });
        $this->app->bind(CentralAccess::class, static fn () => new class implements CentralAccess
        {
            public function assertCanRun(string $operation, Authenticatable $actor): void {}
        });
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
        $operation = new OwnershipOperation('adopt-settings', 'operator', '1');
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['settings'], $this->reviewedMappings(), $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }

    /** @return list<OwnershipMapping> */
    protected function reviewedMappings(): array
    {
        return [];
    }
}
