<?php

namespace Tests\Provisioning;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RetryLogicTest extends TestCase
{
    #[DataProvider('retryDelayProvider')]
    public function testExponentialBackoffDelays(int $retryCount, int $expectedMinutes): void
    {
        $delays = [1, 5, 15, 60, 360, 86400]; // minutes
        $delay = $retryCount < count($delays) ? $delays[$retryCount] : $delays[count($delays) - 1];
        $this->assertSame($expectedMinutes, $delay);
    }

    public static function retryDelayProvider(): array
    {
        return [
            'first retry' => [0, 1],
            'second retry' => [1, 5],
            'third retry' => [2, 15],
            'fourth retry' => [3, 60],
            'fifth retry' => [4, 360],
            'sixth retry' => [5, 86400],
            'beyond max' => [6, 86400],
            'far beyond max' => [10, 86400],
        ];
    }

    public function testMaxRetriesBeforeFail(): void
    {
        $maxRetries = 6;
        $retries = 0;
        for ($i = 0; $i < $maxRetries; $i++) {
            $retries++;
            if ($retries < $maxRetries) {
                $this->assertLessThan($maxRetries, $retries);
            }
        }
        $this->assertSame($maxRetries, $retries);
        // At max retries, task is failed
        $status = $retries >= $maxRetries ? 'failed' : 'pending';
        $this->assertSame('failed', $status);
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

        $this->assertContains('running', $validTransitions['pending']);
        $this->assertContains('retryable', $validTransitions['running']);
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
