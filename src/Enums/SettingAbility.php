<?php

declare(strict_types=1);

namespace Nvl\Settings\Enums;

/**
 * Authorization abilities exposed by the optional management API.
 */
enum SettingAbility: string
{
    case Status = 'status';
    case List = 'list';
    case View = 'view';
    case Set = 'set';
    case Reset = 'reset';
}
