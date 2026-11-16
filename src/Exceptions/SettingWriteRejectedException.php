<?php

declare(strict_types=1);

namespace Nvl\Settings\Exceptions;

/**
 * @api
 *
 * Raised when a model observer or listener vetoes a setting write, so no change is reported or published.
 */
final class SettingWriteRejectedException extends SettingException
{
    /**
     * Create a rejected-write exception for one canonical key.
     */
    public static function forKey(string $key): self
    {
        return new self("Setting [{$key}] write was rejected by its model.");
    }
}
