<?php

declare(strict_types=1);

use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Support\Config\PackageEnvironment;

/** Complete runtime defaults; publication sections are declared in ../resources/config/sections.json. */
return [
    'discovery' => [
        'paths' => [
            base_path('settings'),
        ],
        'patterns' => [
            '*.settings.php',
            '*.settings.json',
        ],
        'recursive' => true,
        'follow_links' => false,
        'maximum_files' => 1_000,
        'maximum_file_bytes' => 262_144,
        'maximum_json_depth' => 64,
        'cache' => PackageEnvironment::get('NVL_SETTINGS_DISCOVERY_CACHE', true),
        'cache_path' => null,
    ],

    'sync' => [
        'respect_db_values' => true,
        'prune' => 'orphan', // orphan | delete | ignore
    ],

    'storage' => [
        'connection' => null,
        'table' => SettingsTables::Settings,
    ],

    'migrations' => [
        'enabled' => true,
    ],

    'adoption' => [
        'maximum_manifest_bytes' => 1_048_576,
        'maximum_records' => 10_000,
    ],

    'management' => [
        'enabled' => false,
        'path' => 'nvl/api/v1/settings',
        'name' => 'nvl.settings.management.',
        'middleware' => ['api', 'auth', 'throttle:60,1'],
        'authorization_ability' => null,
    ],

    'cache' => [
        'enabled' => PackageEnvironment::get('NVL_SETTINGS_CACHE', true),
        'store' => null,
        'key' => 'nvl:settings:v2',
    ],

    'overrides' => [
        'enabled' => PackageEnvironment::get('NVL_SETTINGS_CONFIG_OVERRIDES', false),
        'denied' => [
            'app.key', 'app.debug', 'app.env', 'app.timezone',
            'database.*', 'cache.*', 'nvl-settings.*',
        ],
    ],
];
