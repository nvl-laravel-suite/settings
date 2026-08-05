<?php

declare(strict_types=1);

use Nvl\Settings\Enums\SettingType;

return [
    'namespace' => 'catalog',
    'settings' => [
        'label' => [
            'type' => SettingType::Text,
            'default' => 'Catalog',
            'metadata' => ['weight' => NAN],
        ],
    ],
];
