<?php

declare(strict_types=1);

namespace Nvl\Settings\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

/**
 * Raised when a setting mutation targets an outdated revision.
 */
final class StaleSettingVersionException extends SettingException implements ShouldntReport
{
    /**
     * Create a stale-revision exception for one canonical key.
     */
    public static function forKey(string $key, ?Throwable $previous = null): self
    {
        return new self(
            "Setting [{$key}] changed after it was read.",
            previous: $previous,
        );
    }
}
