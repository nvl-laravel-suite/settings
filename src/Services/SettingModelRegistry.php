<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Settings\Models\Setting;
use Nvl\Support\Models\ConfiguredModelRegistration;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionMethod;

/** Resolves the configured Setting while preserving native revision and ownership persistence.
 * @api
 */
final class SettingModelRegistry
{
    /** @var ConfiguredModelRegistration<Setting> */
    private readonly ConfiguredModelRegistration $registration;

    public function __construct(Repository $configuration, OwnerRegistry $identities)
    {
        $this->registration = new ConfiguredModelRegistration(
            $configuration, $identities, 'nvl-settings.models.setting', Setting::class, 'settings.values',
            static function (string $class): void {
                foreach (['booted', 'ownershipDefinition'] as $method) {
                    if ((new ReflectionMethod($class, $method))->getDeclaringClass()->getName()
                        !== (new ReflectionMethod(Setting::class, $method))->getDeclaringClass()->getName()) {
                        throw new OwnershipConfigurationInvalid('Configured Settings models must retain native revision and resource classification hooks.');
                    }
                }
            },
        );
    }

    /** Freeze the root before resource compilation.
     * @internal
     *
     * @return class-string<Setting>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** @return class-string<Setting> */
    public function settingClass(): string
    {
        return $this->registration->modelClass();
    }

    /** @internal
     * @return class-string<Setting>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->settingClass();
    }

    /** @internal */
    public static function newInstance(): Setting
    {
        $class = self::modelClass();

        return new $class;
    }

    /** @internal
     * @return Builder<Setting>
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
