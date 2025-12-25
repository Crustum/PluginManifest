<?php
declare(strict_types=1);

return [
    'Notification' => [
        'channels' => [
            'database' => [
                'className' => 'Crustum/Notification.Database',
            ],
            'mail' => [
                'className' => 'Crustum/Notification.Mail',
                'profile' => 'default',
            ],
        ],
    ],
];
