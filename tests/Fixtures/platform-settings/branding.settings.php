<?php

declare(strict_types=1);

use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'branding',
    'settings' => [
        'name' => [
            'type' => SettingType::Text,
            'default' => 'Platform name',
            'overrides' => 'app.name',
        ],
    ],
];
