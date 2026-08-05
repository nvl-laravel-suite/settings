<?php

declare(strict_types=1);

namespace Nvl\Settings\Support;

use InvalidArgumentException;

/**
 * Validates the optional management API path and route-name prefix.
 */
final class SettingsRouteConfiguration
{
    /**
     * Return the normalized management API URI prefix.
     */
    public static function path(): string
    {
        $path = config('settings.management.path', 'api/v1/settings');

        if (! is_string($path)) {
            throw new InvalidArgumentException(
                'settings.management.path must be a string.',
            );
        }

        $path = trim($path, '/');

        if ($path === ''
            || str_contains($path, '..')
            || str_contains($path, '//')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/_-]*$#', $path) !== 1) {
            throw new InvalidArgumentException(
                'settings.management.path must be a safe, non-empty route prefix.',
            );
        }

        return $path;
    }

    /**
     * Return a normalized route-name prefix ending in a dot.
     */
    public static function name(): string
    {
        $name = config('settings.management.name', 'nvl.settings.management.');

        if (! is_string($name)) {
            throw new InvalidArgumentException(
                'settings.management.name must be a string.',
            );
        }

        $name = rtrim(trim($name), '.');

        if ($name === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                'settings.management.name must be a safe route-name prefix.',
            );
        }

        return $name.'.';
    }

    private function __construct() {}
}
