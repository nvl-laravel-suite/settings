<?php

declare(strict_types=1);

namespace Nvl\Settings\Enums;

use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Defines how synchronization handles persisted settings removed from source.
 *
 * @internal
 */
#[Hidden]
enum SettingPruneStrategy: string
{
    case Ignore = 'ignore';
    case Orphan = 'orphan';
    case Delete = 'delete';
}
