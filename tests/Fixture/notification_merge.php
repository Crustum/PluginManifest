<?php
declare(strict_types=1);

return [
    'Notification' => [
        'channels' => [
            'slack' => [
                'className' => 'Crustum/NotificationSlack.Slack',
                'webhook_url' => env('SLACK_WEBHOOK_URL'),
            ],
        ],
    ],
];
