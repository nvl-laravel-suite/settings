<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Nvl\Settings\Contracts\SettingsAuthorization;
use Nvl\Settings\Enums\SettingAbility;

/**
 * Gate-backed authorization that fails closed until explicitly configured.
 */
final class ConfiguredSettingsAuthorization implements SettingsAuthorization
{
    /**
     * Authorize through the configured consumer-owned Gate ability.
     */
    public function authorize(SettingAbility $ability, ?string $key = null): void
    {
        $configuredAbility = config('settings.management.authorization_ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'Settings management requires a SettingsAuthorization binding or configured Gate ability.',
            );
        }

        Gate::authorize($configuredAbility, [$ability->value, $key]);
    }
}
