<?php
declare(strict_types=1);

return [
    'App' => [
        'version' => '1.0.0',
    ],
    'Database' => [
        'default' => [
            'password' => env('DB_PASSWORD'),
            'database' => 'myapp',
        ],
    ],
    'Cache' => [
        'default' => [
            'className' => 'File',
        ],
    ],
];
