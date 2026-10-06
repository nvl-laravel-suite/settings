<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Settings\Data\SettingsSourceStatusData;

/**
 * Defines the consumer-facing ValidateSettingsSourcesAction workflow.
 *
 * @api
 */
interface ValidateSettingsSourcesContract
{
    /**
     * Return a sanitized deterministic discovery and definition status.
     */
    public function execute(): SettingsSourceStatusData;
}
