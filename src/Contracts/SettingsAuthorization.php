<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Settings\Enums\SettingAbility;
use Nvl\Support\Enums\SystemActor;

/**
 * Consumer-owned authorization boundary for setting management.
 *
 * @api
 */
interface SettingsAuthorization
{
    /**
     * Authorize one management ability, optionally for a canonical key.
     */
    public function authorize(Authenticatable|SystemActor $actor, SettingAbility $ability, ?string $key = null): void;
}
