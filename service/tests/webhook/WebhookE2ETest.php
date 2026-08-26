<?php

namespace Tests\webhook;

use App\webhook\queue\WebhookSender;
use Common\webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * 供应商 webhook 端到端闭环（真实本机 Redis + PHP 内置 HTTP 假供应商端点）：
 * 事件载荷/签名（真实 buildPayload/signature）→ 入队（消息格式与真实 producer
 * RedisConnection::send 一致）→ 消费者（真实 WebhookSender）→ HTTP 投递 →
 * 供应商侧 verifySignature 验签 → 按事件类型状态流转。
 * Redis 为异步 Client::send，纯 PHPUnit 无法驱动其事件循环，故按真实格式直接入队；
 * 同步投递路径由 dispatchTo 用例覆盖。
 */
final class WebhookE2ETest extends TestCase
{
    private const QUEUE_KEY = '{redis-queue}-waitingwebhook';
    private const SECRET    = 'e2e-test-secret';

    private const EVENTS = [
        WebhookDispatcher::EVENT_ORDER_PAID           => ['order_id' => 1001, 'amount' => '99.00'],
        WebhookDispatcher::EVENT_ORDER_REFUNDED       => ['order_id' => 1002, 'refund_id' => 55],
        WebhookDispatcher::EVENT_RESOURCE_PROVISIONED => ['resource_id' => 2001],
        WebhookDispatcher::EVENT_RESOURCE_EXPIRING    => ['resource_id' => 2002, 'days_left' => 7],
        WebhookDispatcher::EVENT_RESOURCE_DESTROYED   => ['resource_id' => 2003],
        WebhookDispatcher::EVENT_SETTLEMENT_CREATED   => ['settlement_id' => 3001, 'amount' => '12.34'],
        WebhookDispatcher::EVENT_WITHDRAWAL_APPROVED  => ['withdrawal_id' => 4001],
    ];

    private string $recvFile;
    private string $statusFile;
    private string $port;
    private $serverProc;
    private string $url;

    protected function setUp(): void
    {
        if (!getenv('RUN_E2E')) {
            $this->markTestSkipped('E2E 需要本机 Redis + php -S，设 RUN_E2E=1 启用');
        }

        $this->bootstrapRedis();
        putenv('WEBHOOK_SECRET=' . self::SECRET);

        $this->recvFile   = tempnam(sys_get_temp_dir(), 'whrecv');
        $this->statusFile = tempnam(sys_get_temp_dir(), 'whstatus');
        file_put_contents($this->statusFile, '{}');

        $this->port  = (string) random_int(38000, 39999);
        $this->url   = "http://127.0.0.1:{$this->port}/hook";
        $router      = $this->writeRouter();
        $this->serverProc = $this->startServer($router);

        // 假供应商端点已是"已注册"状态（register() 的 SSRF 防护拒绝内网地址，注册逻辑另行单测）
        \support\Redis::sadd('webhook_urls', $this->url);
    }

    protected function tearDown(): void
    {
        if (isset($this->serverProc) && is_resource($this->serverProc)) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        if (isset($this->recvFile)) {
            @unlink($this->recvFile);
        }
        if (isset($this->statusFile)) {
            @unlink($this->statusFile);
        }
        try {
            \support\Redis::del('webhook_urls', self::QUEUE_KEY);
        } catch (\Throwable $e) {
            // Redis 不可用时静默
        }
    }

    public function testSevenEventTypesDeliverEndToEndWithVerificationAndStateTransition(): void
    {
        // 1. 模拟业务方触发 7 类事件：真实载荷/签名构建 + 按真实格式入队
        foreach (self::EVENTS as $event => $data) {
            [$body, $sig] = $this->buildForTest($event, $data);
            $this->enqueue($this->url, $body, $sig, $event);
        }
        $this->assertSame(count(self::EVENTS), (int) \support\Redis::lLen(self::QUEUE_KEY));

        // 2. 消费者处理：真实 WebhookSender 消费全部消息
        $consumer = new WebhookSender();
        while (($msg = \support\Redis::rPop(self::QUEUE_KEY)) !== false) {
            $consumer->consume(json_decode($msg, true)['data']);
        }

        // 3. 假供应商端点收到全部回调；对端点记录的 body+sig 用真实 verifySignature 复验
        //    （端点自身验签失败会回 401 且不记录，能收到即第一层通过）
        $lines = $this->waitForLines(count(self::EVENTS), 10);
        $this->assertCount(count(self::EVENTS), $lines, '端点应收到全部 7 类事件回调');

        $received = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            $this->assertTrue(
                WebhookDispatcher::verifySignature($record['body'], $record['sig'], self::SECRET),
                '端点记录的投递签名应能被 verifySignature 验证'
            );
            $decoded = json_decode($record['body'], true);
            $received[$decoded['event']] = $decoded['data'];
        }
        foreach (self::EVENTS as $event => $data) {
            $this->assertArrayHasKey($event, $received, "事件 {$event} 应被端点收到");
            $this->assertSame($data, $received[$event], "事件 {$event} 载荷应一致");
        }

        // 4. 状态更新：供应商侧按事件类型状态机全部正确流转
        $status = json_decode(file_get_contents($this->statusFile), true);
        $this->assertSame('paid',      $status['order:1001'] ?? null);
        $this->assertSame('refunded',  $status['order:1002'] ?? null);
        $this->assertSame('provisioned', $status['resource:2001'] ?? null);
        $this->assertSame('expiring:7',  $status['resource:2002'] ?? null);
        $this->assertSame('destroyed',   $status['resource:2003'] ?? null);
        $this->assertSame('created:12.34', $status['settlement:3001'] ?? null);
        $this->assertSame('approved',     $status['withdrawal:4001'] ?? null);
    }

    public function testDispatchToDeliversSynchronouslyToRegisteredUrl(): void
    {
        // dispatchTo 同步路径：真实 buildPayload/signature/sendToUrl 直达端点
        $ok = WebhookDispatcher::dispatchTo($this->url, WebhookDispatcher::EVENT_ORDER_PAID, ['order_id' => 777]);
        $this->assertTrue($ok);

        $lines = $this->waitForLines(1, 10);
        $this->assertCount(1, $lines);
        $record = json_decode($lines[0], true);
        $this->assertTrue(
            WebhookDispatcher::verifySignature($record['body'], $record['sig'], self::SECRET)
        );
        $decoded = json_decode($record['body'], true);
        $this->assertSame(WebhookDispatcher::EVENT_ORDER_PAID, $decoded['event']);
        $this->assertSame(['order_id' => 777], $decoded['data']);
    }

    public function testSenderToleratesDeadEndpoint(): void
    {
        // 尽力投递：目标不可达时 consume 不抛异常
        [$body, $sig] = $this->buildForTest(WebhookDispatcher::EVENT_ORDER_PAID, ['order_id' => 1]);
        $this->enqueue('http://127.0.0.1:9/dead', $body, $sig, WebhookDispatcher::EVENT_ORDER_PAID);

        $consumer = new WebhookSender();
        $msg = json_decode(\support\Redis::rPop(self::QUEUE_KEY), true);
        $consumer->consume($msg['data']);
        $this->addToAssertionCount(1);
    }

    public function testRegisterRejectsPrivateAndLoopbackUrls(): void
    {
        foreach (['http://127.0.0.1/hook', 'http://10.1.2.3/hook', 'http://192.168.0.1/hook', 'http://localhost/hook'] as $bad) {
            try {
                WebhookDispatcher::register($bad);
                $this->fail("register 应拒绝内网 URL: {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('private/internal', $e->getMessage());
            }
        }
        // 公网 IP 字面量允许（无 DNS 依赖）
        WebhookDispatcher::register('https://8.8.8.8/hook');
        $this->assertContains('https://8.8.8.8/hook', WebhookDispatcher::list());
        WebhookDispatcher::unregister('https://8.8.8.8/hook');
    }

    public function testDispatchToUnregisteredUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebhookDispatcher::dispatchTo('https://8.8.8.8/hook', 'test.ping', []);
    }

    private function bootstrapRedis(): void
    {
        // sendToUrl 失败分支经 support\Log 打日志，需真实 log 配置（同 AuthFullChainTest 惯例）
        \Webman\Config::load(__DIR__ . '/../../config', ['route']);
        $ref = new \ReflectionProperty(\support\Redis::class, 'manager');
        $cfg = require __DIR__ . '/../../config/redis.php';
        $ref->setValue(null, new \Illuminate\Redis\RedisManager('default', 'phpredis', $cfg));
        // WebhookDispatcher 经 Illuminate Redis Facade 访问，需根容器绑定
        $container = new \Illuminate\Container\Container();
        $container->singleton('redis', fn() => \support\Redis::manager());
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        \support\Redis::del('webhook_urls', self::QUEUE_KEY);
    }

    private function buildForTest(string $event, array $data): array
    {
        $dispatcher = new class extends WebhookDispatcher {
            public static function buildForTest(string $event, array $payload): array
            {
                $body = self::buildPayload($event, $payload);
                return [$body, self::signature($body, 'e2e-test-secret')];
            }
        };
        return $dispatcher::buildForTest($event, $data);
    }

    private function enqueue(string $url, string $body, string $sig, string $event): void
    {
        $package = json_encode([
            'id'       => microtime(true) . '.' . random_int(1, 999999),
            'time'     => time(),
            'delay'    => 0,
            'attempts' => 0,
            'queue'    => 'webhook',
            'data'     => ['url' => $url, 'body' => $body, 'sig' => $sig, 'event' => $event],
        ]);
        \support\Redis::lPush(self::QUEUE_KEY, $package);
    }

    private function writeRouter(): string
    {
        $autoload = var_export(__DIR__ . '/../../vendor/autoload.php', true);
        $recv     = var_export($this->recvFile, true);
        $status   = var_export($this->statusFile, true);
        $router   = <<<PHP
<?php
require {$autoload};
\$body  = file_get_contents('php://input');
\$sig   = \$_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
if (!\Common\webhook\WebhookDispatcher::verifySignature(\$body, \$sig, getenv('WEBHOOK_SECRET'))) {
    http_response_code(401);
    echo 'signature-invalid';
    return;
}
file_put_contents({$recv}, json_encode(['body' => \$body, 'sig' => \$sig]) . "\n", FILE_APPEND);
\$d  = json_decode(\$body, true);
\$st = json_decode(file_get_contents({$status}), true);
\$key = null; \$val = null;
switch (\$d['event']) {
    case 'order.paid':           \$key = 'order:' . \$d['data']['order_id'];     \$val = 'paid';        break;
    case 'order.refunded':       \$key = 'order:' . \$d['data']['order_id'];     \$val = 'refunded';    break;
    case 'resource.provisioned': \$key = 'resource:' . \$d['data']['resource_id']; \$val = 'provisioned'; break;
    case 'resource.expiring':    \$key = 'resource:' . \$d['data']['resource_id']; \$val = 'expiring:' . \$d['data']['days_left']; break;
    case 'resource.destroyed':   \$key = 'resource:' . \$d['data']['resource_id']; \$val = 'destroyed';   break;
    case 'settlement.created':   \$key = 'settlement:' . \$d['data']['settlement_id']; \$val = 'created:' . \$d['data']['amount']; break;
    case 'withdrawal.approved':  \$key = 'withdrawal:' . \$d['data']['withdrawal_id']; \$val = 'approved';  break;
}
if (\$key !== null) {
    \$st[\$key] = \$val;
    file_put_contents({$status}, json_encode(\$st));
}
http_response_code(200);
echo 'ok';
PHP;
        $file = tempnam(sys_get_temp_dir(), 'whrouter') . '.php';
        file_put_contents($file, $router);
        return $file;
    }

    private function startServer(string $router)
    {
        $cmd = sprintf(
            'php -S 127.0.0.1:%s %s',
            $this->port,
            escapeshellarg($router)
        );
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            $this->fail('无法启动 php -S 假供应商端点');
        }
        $deadline = microtime(true) + 5;
        do {
            usleep(50000);
            $sock = @fsockopen('127.0.0.1', (int) $this->port, $errno, $errstr, 0.2);
        } while (!$sock && microtime(true) < $deadline);
        if ($sock) {
            fclose($sock);
        } else {
            $this->fail("假供应商端点 {$this->port} 未在 5s 内就绪");
        }
        return $proc;
    }

    private function waitForLines(int $count, int $timeoutSec): array
    {
        $deadline = microtime(true) + $timeoutSec;
        do {
            $lines = @file($this->recvFile, FILE_IGNORE_NEW_LINES) ?: [];
            if (count($lines) >= $count) {
                return $lines;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        return $lines;
    }
}
