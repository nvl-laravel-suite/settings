<?php

declare(strict_types=1);

namespace Nvl\Settings\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Settings\Adapters\Laravel\LaravelSettingsAuditContextProvider;
use Nvl\Settings\Commands\AdoptCommand;
use Nvl\Settings\Commands\CacheCommand;
use Nvl\Settings\Commands\ClearCommand;
use Nvl\Settings\Commands\DoctorCommand;
use Nvl\Settings\Commands\ListCommand;
use Nvl\Settings\Commands\ResetCommand;
use Nvl\Settings\Commands\SyncCommand;
use Nvl\Settings\Commands\ValidateCommand;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Models\Setting as SettingModel;
use Nvl\Settings\Observers\SettingCacheObserver;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\ConfiguredSettingsAuthorization;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\SettingManager;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\SettingsRules;
use Throwable;

/**
 * Registers settings discovery, persistence, commands, and optional config overrides.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Register package configuration and repository services.
     */
    public function register(): void
    {
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/settings.php', 'settings');

        $this->app->singleton(DefinitionRepository::class);
        $this->app->singleton(SettingCache::class);
        $this->app->singleton(ConfigOverrideApplier::class);
        $this->app->singleton(SettingRepository::class, SettingManager::class);
        $this->app->alias(SettingRepository::class, 'settings');
        $this->app->bindIf(SettingsAuthorization::class, ConfiguredSettingsAuthorization::class);
        $this->app->bindIf(
            SettingsAuditContextProvider::class,
            LaravelSettingsAuditContextProvider::class,
        );
    }

    /**
     * Publish resources, register commands, and attach cache invalidation hooks.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/settings');
        $this->publishes([
            __DIR__.'/../../config/settings.php' => config_path('settings.php'),
        ], 'settings-config');

        $this->publishes([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'settings-migrations');
        if ((bool) config('settings.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        if ((bool) config('settings.management.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        }

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'settings-skills');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdoptCommand::class,
                SyncCommand::class,
                ResetCommand::class,
                CacheCommand::class,
                ClearCommand::class,
                ListCommand::class,
                DoctorCommand::class,
                ValidateCommand::class,
            ]);
        }

        SettingModel::observe(SettingCacheObserver::class);

        $this->registerValidationRules();

        $this->applyConfigOverrides();
    }

    /**
     * Register portable string aliases for first-party JSON collection rules.
     */
    private function registerValidationRules(): void
    {
        Validator::extend(
            'settings_integer_list_between',
            static fn (string $attribute, mixed $value, array $parameters): bool => SettingsRules::integerListBetweenParameters($parameters)
                ->isValid($value),
            'The :attribute must be a list of integers inside the configured range.',
        );
        Validator::extend(
            'settings_integer_map_between',
            static fn (string $attribute, mixed $value, array $parameters): bool => SettingsRules::integerMapBetweenParameters($parameters)
                ->isValid($value),
            'The :attribute must be a string-keyed map of integers inside the configured range.',
        );
    }

    /**
     * Apply opted-in overrides only after the application has booted and the table exists.
     */
    private function applyConfigOverrides(): void
    {
        if (! config('settings.overrides.enabled')) {
            return;
        }

        $this->app->booted(function (): void {
            $model = new SettingModel;
            $connection = $model->getConnectionName();

            try {
                $tableExists = Schema::connection($connection)->hasTable($model->getTable());
            } catch (Throwable) {
                return;
            }

            if (! $tableExists) {
                return;
            }

            $this->app->make(ConfigOverrideApplier::class)->apply();
        });
    }
}
