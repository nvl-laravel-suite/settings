<?php

declare(strict_types=1);

return [
    'namespace' => 'catalog',
    'settings' => [
        'page_size' => [
            'type' => 'int',
            'default' => 24,
            'rules' => [
                static function (string $attribute, mixed $value, Closure $fail): void {},
            ],
        ],
    ],
];
