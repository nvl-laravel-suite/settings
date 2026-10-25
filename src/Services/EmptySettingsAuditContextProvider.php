<?php

declare(strict_types=1);

namespace Nvl\Settings\Services;

use Nvl\Settings\Contracts\SettingsAuditContextProvider;

/** Default metadata source; management operations remain independent of HTTP state. */
final readonly class EmptySettingsAuditContextProvider implements SettingsAuditContextProvider
{
    public function metadata(): array
    {
        return [];
    }
}
