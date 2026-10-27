<?php

declare(strict_types=1);

namespace Nvl\Settings\Tests\Fixtures;

use Nvl\Settings\Models\Setting;

/** A host projection on a separately configured source connection. */
class ExtendedSetting extends Setting
{
    protected $connection = 'settings-root';

    public function displayKey(): string
    {
        return strtoupper($this->fullKey());
    }
}
