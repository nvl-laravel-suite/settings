<?php

declare(strict_types=1);

use Nvl\Settings\Definitions\Tables\SettingsTables;
use Nvl\Settings\Models\Setting;
use Nvl\Support\Config\PackageEnvironment;

return [
    'models' => [
        'setting' => Setting::class,
    ],

    'discovery' => [
        'paths' => [base_path('settings')],
        'patterns' => ['*.settings.php', '*.settings.json'],
        'recursive' => true,
        'follow_links' => false,
        'cache' => PackageEnvironment::get('NVL_SETTINGS_DISCOVERY_CACHE', true),
        'cache_path' => null,
    ],
    'storage' => ['connection' => null, 'table' => SettingsTables::Settings],
    'migrations' => ['enabled' => true],
    'management' => ['authorization_ability' => null],
    'cache' => ['enabled' => PackageEnvironment::get('NVL_SETTINGS_CACHE', true), 'store' => null],
    'overrides' => ['enabled' => PackageEnvironment::get('NVL_SETTINGS_CONFIG_OVERRIDES', false)],
];
