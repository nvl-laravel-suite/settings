<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Data\Data\PaginatedCollection;
use Nvl\Settings\Data\SettingListQueryData;

/**
 * Defines the consumer-facing ListSettingsAction workflow.
 *
 * @api
 */
interface ListSettingsContract
{
    /**
     * Resolve one filtered management page.
     */
    public function execute(SettingListQueryData $query): PaginatedCollection;
}
