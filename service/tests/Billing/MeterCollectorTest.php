<?php

namespace Tests\Billing;

use App\Billing\Service\MeterCollector;
use App\Provisioning\Model\Resource;
use PHPUnit\Framework\TestCase;

final class MeterCollectorTest extends TestCase
{
    // CDN metrics are provider-independent constants; the emitted key
    // contract is what UsageAggregator::mapMetricToMeter depends on.

    public function testCdnMetricsEmitBillableMeters(): void
    {
        $method = new \ReflectionMethod(MeterCollector::class, 'collectCdnMetrics');
        $method->setAccessible(true);
        $metrics = $method->invoke(new MeterCollector(), new Resource());

        $this->assertSame(['cdn_bandwidth_gb' => 0, 'cdn_requests' => 0], $metrics);
    }
}
