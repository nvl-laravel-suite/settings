<?php

declare(strict_types=1);

namespace Nvl\Settings\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Provides static access to the consumer-facing settings repository.
 *
 * @method static mixed get(string $key)
 * @method static bool has(string $key)
 *
 * @api
 */
final class Setting extends Facade
{
    /**
     * Return the settings repository container binding.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'nvl.settings';
    }
}
