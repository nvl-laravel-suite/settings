<?php

declare(strict_types=1);

use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'interface',
    'settings' => [
        'theme' => [
            'type' => SettingType::Enum,
            'default' => 'light',
            'rules' => ['in:light,dark'],
            'description' => 'Default interface theme.',
            'metadata' => ['group' => 'appearance'],
        ],
    ],
];
