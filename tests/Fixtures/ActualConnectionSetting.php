<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Nvl\Settings\Models\Setting;

/** A supported host model with an explicit source instead of the package default. */
class ActualConnectionSetting extends Setting
{
    protected $connection = 'settings-actual';
}
