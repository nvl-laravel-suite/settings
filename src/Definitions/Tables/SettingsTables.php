<?php

declare(strict_types=1);

namespace Nvl\Settings\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Defines the canonical table names owned by the Settings package.
 */
final class SettingsTables
{
    public const string Settings = 'nvl_settings_settings';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('settings', $key);
    }

    private function __construct() {}
}
