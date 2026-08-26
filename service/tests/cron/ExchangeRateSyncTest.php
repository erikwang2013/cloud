<?php

namespace Tests\cron;

use App\cron\ExchangeRateSync;
use PHPUnit\Framework\TestCase;

/**
 * 汇率同步的容错契约：API 不可达 / Redis 不可用时 run() 必须吞掉异常，
 * 不允许把未决状态抛给调度器（失败由下轮 cron 重试）。
 */
final class ExchangeRateSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('EXCHANGE_RATE_API_URL');
        parent::tearDown();
    }

    public function testRunSwallowsUnreachableApi(): void
    {
        putenv('EXCHANGE_RATE_API_URL=http://127.0.0.1:1/unreachable');

        // 故意访问不可达地址：抑制 PHP 的 file_get_contents warning，
        // 验证 run() 安静完成（失败由下轮 cron 重试，不抛给调度器）
        set_error_handler(static fn () => true, E_WARNING);
        try {
            ob_start();
            (new ExchangeRateSync())->run();
            ob_end_clean();
        } finally {
            restore_error_handler();
        }

        $this->addToAssertionCount(1);
    }

    public function testRunWithValidPayloadDoesNotThrowWhenRedisDown(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'rates');
        file_put_contents($fixture, json_encode(['rates' => ['USD' => 1, 'CNY' => 7.2]]));
        putenv('EXCHANGE_RATE_API_URL=file://' . $fixture);

        // Redis facade 无应用容器时抛 Error，必须被 run() 内部捕获
        ob_start();
        (new ExchangeRateSync())->run();
        ob_end_clean();

        unlink($fixture);
        $this->addToAssertionCount(1); // 未抛出即契约满足
    }
}
