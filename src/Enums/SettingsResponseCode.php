<?php

declare(strict_types=1);

namespace Nvl\Settings\Enums;

use Nvl\Support\Contracts\ResponseCode;

/** Stable public response discriminators for Settings.
 * @api
 */
enum SettingsResponseCode: string implements ResponseCode
{
    case OperationFailed = 'operation_failed';
    case StaleSettingVersion = 'stale_setting_version';
    case UnknownSetting = 'unknown_setting';
    case DuplicateSetting = 'duplicate_setting';
    case InvalidDefinition = 'invalid_definition';
}
