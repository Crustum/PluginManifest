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
            'slack' => [
                'className' => 'Crustum/NotificationSlack.Slack',
                'webhook_url' => env('SLACK_WEBHOOK_URL'),
            ],
        ],
    ],
];
