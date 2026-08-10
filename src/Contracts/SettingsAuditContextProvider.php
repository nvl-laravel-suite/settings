<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Settings\Data\SettingAuditContextData;

/**
 * Supplies value-free actor and request context for setting mutation events.
 */
interface SettingsAuditContextProvider
{
    /**
     * Capture the current mutation context before the transaction commits.
     */
    public function current(): SettingAuditContextData;
}
