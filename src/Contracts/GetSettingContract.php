<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Settings\Data\SettingValueData;

/**
 * Defines the consumer-facing GetSettingAction workflow.
 *
 * @api
 */
interface GetSettingContract
{
    /**
     * Resolve one definition against its optional persisted record.
     */
    public function execute(string $key): SettingValueData;
}
