<?php

declare(strict_types=1);

namespace Nvl\Settings\Enums;

/**
 * Defines how synchronization handles persisted settings removed from source.
 *
 * @api
 */
enum SettingPruneStrategy: string
{
    case Ignore = 'ignore';
    case Orphan = 'orphan';
    case Delete = 'delete';
}
