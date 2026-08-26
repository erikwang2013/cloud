<?php
namespace App\webhook\queue;

use Common\webhook\WebhookDispatcher;
use Webman\RedisQueue\Consumer;

class WebhookSender implements Consumer
{
    public string $queue = 'webhook';

    public function consume($data)
    {
        // 失败仅记录日志：webhook 为尽力投递，注册端可查询重放
        WebhookDispatcher::sendToUrl(
            $data['url'],
            $data['body'],
            $data['sig'],
            $data['event']
        );
    }
}
