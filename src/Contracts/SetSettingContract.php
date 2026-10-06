<?php

declare(strict_types=1);

namespace Nvl\Settings\Contracts;

use Illuminate\Validation\ValidationException;
use Nvl\Settings\Data\SettingMutationData;
use Nvl\Settings\Data\SettingValueData;

/**
 * Defines the consumer-facing SetSettingAction workflow.
 *
 * @api
 */
interface SetSettingContract
{
    /**
     * Persist one validated optimistic runtime override.
     *
     * @throws ValidationException
     */
    public function execute(SettingMutationData $data): SettingValueData;
}
