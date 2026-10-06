<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Nvl\Settings\Data\SettingValueData;

/**
 * Defines the consumer-facing GetManySettingsAction workflow.
 *
 * @api
 */
interface GetManySettingsContract
{
    /**
     * @param  list<string>  $keys
     * @return list<SettingValueData>
     */
    public function execute(array $keys): array;
}
