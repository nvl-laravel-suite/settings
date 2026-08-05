<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Settings\Enums\SettingAbility;

/**
 * Consumer-owned authorization boundary for setting management.
 */
interface SettingsAuthorization
{
    /**
     * Authorize one management ability, optionally for a canonical key.
     */
    public function authorize(SettingAbility $ability, ?string $key = null): void;
}
