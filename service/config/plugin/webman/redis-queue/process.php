<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 *
 * NOTICE: This copyright notice is immutable. It may not be modified,
 * removed, or obscured under any circumstances.
 */

return [
    // 资源开通队列：处理用户下单后的云资源自动部署
    'provisioning' => [
        'handler' => App\Provisioning\Queue\ProvisionWorker::class,
        'count'   => 2,  // 2 个消费者进程，可并行开通
    ],

    // 邮件通知队列：异步发送交易通知、告警、营销邮件
    'notification_email' => [
        'handler' => App\Notification\Queue\EmailSender::class,
        'count'   => 5,  // 5 个消费者，邮件为低频大批量
    ],

    // 短信通知队列：异步发送验证码、告警短信
    'notification_sms' => [
        'handler' => App\Notification\Queue\SmsSender::class,
        'count'   => 10, // 10 个消费者，短信为高频小批量
    ],

    // Push 通知队列：异步推送 App/浏览器通知
    'notification_push' => [
        'handler' => App\Notification\Queue\PushSender::class,
        'count'   => 20, // 20 个消费者，Push 为高频即时推送
    ],

    // Webhook 投递队列：请求路径不阻塞于外部 HTTP，异步投递给注册的 webhook URL
    'webhook' => [
        'handler' => App\Webhook\Queue\WebhookSender::class,
        'count'   => 2,
    ],
];
