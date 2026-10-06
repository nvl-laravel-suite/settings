<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Settings\Data\SettingValueData;

/**
 * Defines the consumer-facing ResetSettingAction workflow.
 *
 * @api
 */
interface ResetSettingContract
{
    /**
     * Clear one override when its optimistic revision still matches.
     */
    public function execute(string $key, int $expectedRevision): SettingValueData;
}
