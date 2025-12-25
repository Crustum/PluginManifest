<?php
declare(strict_types=1);

return [
    'App' => [
        'name' => 'My Application',
        'debug' => false,
        'version' => '1.0.0',
    ],
    'Database' => [
        'default' => [
            'host' => 'localhost',
            'username' => 'root',
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
