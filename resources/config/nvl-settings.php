<?php

declare(strict_types=1);

use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Settings\Models\Setting;

return [
    'models' => [
        'setting' => Setting::class,
    ],

    'discovery' => [
        'paths' => [base_path('settings')],
        'patterns' => ['*.settings.php', '*.settings.json'],
        'recursive' => true,
        'follow_links' => false,
        'cache' => env('NVL_SETTINGS_DISCOVERY_CACHE', true),
        'cache_path' => null,
    ],
    'migrations' => ['enabled' => true],
    'management' => ['authorization_ability' => null],
    'cache' => ['enabled' => env('NVL_SETTINGS_CACHE', true), 'store' => null],
    'overrides' => ['enabled' => env('NVL_SETTINGS_CONFIG_OVERRIDES', false)],
    'connection' => null,
    'tables' => ['settings' => SettingsTables::Settings],
];
