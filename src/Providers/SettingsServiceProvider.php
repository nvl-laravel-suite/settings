<?php

declare(strict_types=1);

namespace Nvl\Settings\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Settings\Console\Commands\AdoptCommand;
use Nvl\Settings\Console\Commands\CacheCommand;
use Nvl\Settings\Console\Commands\ClearCommand;
use Nvl\Settings\Console\Commands\DoctorCommand;
use Nvl\Settings\Console\Commands\ListCommand;
use Nvl\Settings\Console\Commands\SyncCommand;
use Nvl\Settings\Console\Commands\ValidateCommand;
use Nvl\Settings\Contracts\SettingRepository;
use Nvl\Settings\Contracts\SettingsAuditContextProvider;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Models\Setting as SettingModel;
use Nvl\Settings\Observers\SettingCacheObserver;
use Nvl\Settings\Ownership\SettingsAdoptionAdapter as OwnershipAdopter;
use Nvl\Settings\Ownership\SettingsContextParticipant;
use Nvl\Settings\Ownership\SettingsResourceRegistrar as OwnershipRegistrar;
use Nvl\Settings\Services\ConfigOverrideApplier;
use Nvl\Settings\Services\ConfiguredSettingsAuthorization;
use Nvl\Settings\Services\EmptySettingsAuditContextProvider;
use Nvl\Settings\Services\PlatformConfigWriter;
use Nvl\Settings\Services\PlatformSettingsBootstrap;
use Nvl\Settings\Services\PlatformSettingsReader;
use Nvl\Settings\Services\PlatformSettingsRuntime;
use Nvl\Settings\Services\SettingCache;
use Nvl\Settings\Services\SettingReader;
use Nvl\Settings\Services\SettingsDoctor;
use Nvl\Settings\Services\SettingWriter;
use Nvl\Settings\SettingManager;
use Nvl\Settings\Support\DefinitionRepository;
use Nvl\Settings\Support\SettingsRules;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipContextParticipants;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use WeakMap;

/**
 * Registers settings discovery, persistence, commands, and optional config overrides.
 */
final class SettingsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /**
     * Register package configuration and repository services.
     */
    public function register(): void
    {
        PackageDoctorContributor::register($this->app, 'nvl/settings', fn (): array => $this->app->make(SettingsDoctor::class)->inspect());

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-settings.php', 'settings');
        $this->app->booted(function (): void {
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('settings', OwnershipAdopter::class);
            }
        });

        $this->app->singleton(DefinitionRepository::class);
        $this->app->scoped(SettingCache::class);
        $this->app->singleton(PlatformConfigWriter::class);
        $this->app->scoped(PlatformSettingsReader::class);
        $this->app->scoped(PlatformSettingsRuntime::class);
        $this->app->scoped(PlatformSettingsBootstrap::class);
        $this->app->scoped(ConfigOverrideApplier::class);
        $this->app->scoped(SettingReader::class);
        $this->app->scoped(SettingWriter::class);
        $this->app->scopedIf(SettingRepository::class, SettingManager::class);
        $names = $this->app->make(GlobalNames::class);
        $exists = fn (string $name): bool => $this->app->bound($name);
        $install = fn (string $name) => $this->app->alias(SettingRepository::class, $name);
        $names->reserve('settings', 'container', 'nvl.settings', $exists, $install);
        $names->register('settings', 'container', 'settings', 'nvl.settings', $exists, $install);
        $this->app->bindIf(SettingsAuthorization::class, ConfiguredSettingsAuthorization::class);
        $this->app->bindIf(
            SettingsAuditContextProvider::class,
            EmptySettingsAuditContextProvider::class,
        );
    }

    /**
     * Publish resources, register commands, and attach cache invalidation hooks.
     */
    public function boot(): void
    {
        (new OwnershipRegistrar)->register($this->app->make(OwnershipResourceRegistry::class), $this->app->make('config')->get('nvl-ownership.enabled') === true);
        $this->app->make(OwnershipContextParticipants::class)->register(SettingsContextParticipant::class);
        $this->app->make(GlobalNames::class)->translations('settings', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-settings'),
        ], 'nvl-settings-translations');
        $this->publishes([
            __DIR__.'/../../config/nvl-settings.php' => config_path('nvl-settings.php'),
        ], 'settings-config');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'settings-migrations');
        if ((bool) config('nvl-settings.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'settings-skills');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdoptCommand::class,
                SyncCommand::class,
                CacheCommand::class,
                ClearCommand::class,
                ListCommand::class,
                DoctorCommand::class,
                ValidateCommand::class,
            ]);
        }

        SettingModel::observe(SettingCacheObserver::class);

        $this->registerValidationRules();

        $this->registerConfigOverrides();
    }

    /**
     * Register portable string aliases for first-party JSON collection rules.
     */
    private function registerValidationRules(): void
    {
        Validator::extend(
            'settings_integer_list_between',
            static fn (string $attribute, mixed $value, array $parameters): bool => SettingsRules::integerListBetweenParameters(
                self::validationRuleParameters($parameters),
            )
                ->isValid($value),
            'The :attribute must be a list of integers inside the configured range.',
        );
        Validator::extend(
            'settings_integer_map_between',
            static fn (string $attribute, mixed $value, array $parameters): bool => SettingsRules::integerMapBetweenParameters(
                self::validationRuleParameters($parameters),
            )
                ->isValid($value),
            'The :attribute must be a string-keyed map of integers inside the configured range.',
        );
    }

    /**
     * Validate Laravel's untyped rule parameters before entering the public rule factory.
     *
     * @param  array<int|string, mixed>  $parameters
     * @return list<string>
     */
    private static function validationRuleParameters(array $parameters): array
    {
        if (! array_is_list($parameters)) {
            throw new InvalidArgumentException('Settings integer collection rule parameters must be a list of strings.');
        }

        $validatedParameters = [];

        foreach ($parameters as $parameter) {
            if (! is_string($parameter)) {
                throw new InvalidArgumentException('Settings integer collection rule parameters must be a list of strings.');
            }

            $validatedParameters[] = $parameter;
        }

        return $validatedParameters;
    }

    /**
     * Attach opted-in request and job boundaries without reading storage during bootstrap.
     */
    private function registerConfigOverrides(): void
    {
        if (config('nvl-settings.overrides.enabled') !== true) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);
        /** @var WeakMap<Job, PlatformSettingsRuntime> $runtimes */
        $runtimes = new WeakMap;
        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($runtimes): void {
            if (! $event->job->isDeleted()) {
                $runtime = $this->app->make(PlatformSettingsRuntime::class);
                $runtimes[$event->job] = $runtime;
                $runtime->apply();
            }
        });
        $events->listen(JobAttempted::class, static function (JobAttempted $event) use ($runtimes): void {
            if (isset($runtimes[$event->job])) {
                $runtimes[$event->job]->restore();
                unset($runtimes[$event->job]);
            }
        });
    }
}
