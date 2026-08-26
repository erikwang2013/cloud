<?php

namespace Tests\billing;

use App\billing\service\MeterCollector;
use App\provisioning\model\Resource;
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
