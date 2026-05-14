<?php
return [
    'provisioning' => [
        'handler' => App\Provisioning\Queue\ProvisionWorker::class,
        'count'   => 2,
    ],
    'notification_email' => [
        'handler' => App\Notification\Queue\EmailSender::class,
        'count'   => 5,
    ],
    'notification_sms' => [
        'handler' => App\Notification\Queue\SmsSender::class,
        'count'   => 10,
    ],
    'notification_push' => [
        'handler' => App\Notification\Queue\PushSender::class,
        'count'   => 20,
    ],
];
