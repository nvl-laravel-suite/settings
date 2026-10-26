<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Support\Enums\SystemActor;

/**
 * Gate-backed authorization that fails closed until explicitly configured.
 *
 * @internal
 */
final class ConfiguredSettingsAuthorization implements SettingsAuthorization
{
    /**
     * Authorize through the configured consumer-owned Gate ability.
     */
    public function authorize(Authenticatable|SystemActor $actor, SettingAbility $ability, ?string $key = null): void
    {
        $configuredAbility = config('nvl-settings.management.authorization_ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'Settings management requires a SettingsAuthorization binding or configured Gate ability.',
            );
        }

        if ($actor instanceof SystemActor) {
            throw new AuthorizationException('Background Settings management requires an explicit SettingsAuthorization binding.');
        }

        Gate::forUser($actor)->authorize($configuredAbility, [$ability->value, $key]);
    }
}
