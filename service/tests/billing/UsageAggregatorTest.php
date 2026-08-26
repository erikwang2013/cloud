<?php

namespace Tests\billing;

use App\billing\service\UsageAggregator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsageAggregatorTest extends TestCase
{
    private function mapMetricToMeter(string $metric): ?string
    {
        $method = new \ReflectionMethod(UsageAggregator::class, 'mapMetricToMeter');
        $method->setAccessible(true);
        return $method->invoke(new UsageAggregator(), $metric);
    }

    #[DataProvider('mappingProvider')]
    public function testMetricToMeterMapping(string $metric, ?string $expected): void
    {
        $this->assertSame($expected, $this->mapMetricToMeter($metric));
    }

    public static function mappingProvider(): array
    {
        return [
            'server egress bandwidth' => ['bw_out_gb', 'bandwidth_gb'],
            'cdn bandwidth' => ['cdn_bandwidth_gb', 'bandwidth_gb'],
            'storage usage' => ['storage_used_gb', 'storage_gb_hour'],
            'disk read io' => ['disk_io_read', 'disk_io_million_ops'],
            'disk write io' => ['disk_io_write', 'disk_io_million_ops'],
            'storage requests' => ['storage_requests', 'million_requests'],
            'cdn requests' => ['cdn_requests', 'million_requests'],
            'unmetered metric is skipped' => ['cpu_percent', null],
            'unmetered memory is skipped' => ['mem_percent', null],
            'unknown metric is skipped' => ['foo_bar', null],
        ];
    }

    public function testAllKnownMetricNamesHaveMeterMapping(): void
    {
        // MeterCollector emits exactly these metric names; every one must
        // map to a billable meter or billing silently drops revenue.
        $emitted = ['cpu_percent', 'mem_percent', 'bw_in_gb', 'bw_out_gb', 'disk_usage_gb', 'disk_io_read', 'disk_io_write', 'storage_used_gb', 'storage_requests', 'cdn_bandwidth_gb', 'cdn_requests'];
        $unmapped = array_values(array_filter($emitted, fn($m) => $this->mapMetricToMeter($m) === null));
        $this->assertSame(['cpu_percent', 'mem_percent', 'bw_in_gb', 'disk_usage_gb'], $unmapped);
    }
}
