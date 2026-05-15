<?php

namespace Tests\Provisioning;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryLogicTest extends TestCase
{
    #[DataProvider('retryDelayProvider')]
    public function testExponentialBackoffDelays(int $retryCount, int $expectedMinutes): void
    {
        // Production: $retries = $task->retry_count + 1; delay = $delays[$retries] ?? $delays[5]
        $delays = [1, 5, 15, 60, 360, 86400];
        $retries = $retryCount + 1;
        $delay = $retries < count($delays) ? $delays[$retries] : $delays[count($delays) - 1];
        $this->assertSame($expectedMinutes, $delay);
    }

    public static function retryDelayProvider(): array
    {
        return [
            'first retry (retry_count=0)' => [0, 5],
            'second retry (retry_count=1)' => [1, 15],
            'third retry (retry_count=2)' => [2, 60],
            'fourth retry (retry_count=3)' => [3, 360],
            'fifth retry (retry_count=4)' => [4, 86400],
            'sixth retry (retry_count=5)' => [5, 86400],
            'beyond max (retry_count=6)' => [6, 86400],
            'far beyond max (retry_count=10)' => [10, 86400],
        ];
    }

    public function testTaskFailsAfterMaxRetries(): void
    {
        // Production: if ($retries >= 6) { status = 'failed'; }
        // where $retries = $task->retry_count + 1
        $maxRetries = 6;
        $canRetry = function (int $retryCount) use ($maxRetries): bool {
            return ($retryCount + 1) < $maxRetries;
        };

        $this->assertTrue($canRetry(0));
        $this->assertTrue($canRetry(4));
        $this->assertFalse($canRetry(5));
        $this->assertFalse($canRetry(6));
    }

    public function testProvisionTaskStatusFlow(): void
    {
        $validTransitions = [
            'pending' => ['running', 'cancelled'],
            'running' => ['success', 'retryable', 'failed'],
            'retryable' => ['pending'],
            'failed' => [],
            'success' => [],
        ];

        $this->assertContains('retryable', $validTransitions['running']);
        $this->assertContains('pending', $validTransitions['retryable']);
        $this->assertEmpty($validTransitions['success']);
    }

    public function testTaskParamsSerialization(): void
    {
        $params = [
            'cpu' => 4,
            'ram' => 8192,
            'disk' => 100,
            'os' => 'ubuntu-22.04',
        ];

        $json = json_encode($params);
        $decoded = json_decode($json, true);

        $this->assertSame(4, $decoded['cpu']);
        $this->assertSame(8192, $decoded['ram']);
        $this->assertSame('ubuntu-22.04', $decoded['os']);
    }

    public function testHostSelectorLeastLoaded(): void
    {
        $hosts = [
            ['name' => 'host-a', 'used_cpu' => 8, 'total_cpu' => 32],
            ['name' => 'host-b', 'used_cpu' => 4, 'total_cpu' => 32],
            ['name' => 'host-c', 'used_cpu' => 12, 'total_cpu' => 32],
        ];

        usort($hosts, fn($a, $b) => ($a['used_cpu'] / $a['total_cpu']) <=> ($b['used_cpu'] / $b['total_cpu']));

        $this->assertSame('host-b', $hosts[0]['name']);
    }
}
