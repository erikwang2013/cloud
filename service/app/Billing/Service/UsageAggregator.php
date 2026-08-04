<?php
namespace App\Billing\Service;

use Illuminate\Database\Capsule\Manager as Capsule;

class UsageAggregator
{
    public function aggregate(): void
    {
        $periodEnd   = date('Y-m-d H:i:00');
        $periodStart = date('Y-m-d H:i:00', strtotime('-1 hour'));

        $rows = Capsule::table('resource_metrics')
            ->select('resource_id', 'metric', Capsule::raw('SUM(value) as total'), Capsule::raw('COUNT(*) as samples'))
            ->whereBetween('sample_at', [$periodStart, $periodEnd])
            ->groupBy('resource_id', 'metric')
            ->get();

        foreach ($rows as $row) {
            $meter = $this->mapMetricToMeter($row->metric);
            if (!$meter) continue;

            Capsule::table('usage_events')->updateOrInsert(
                [
                    'resource_id' => $row->resource_id,
                    'meter'       => $meter,
                    'period_start' => $periodStart,
                ],
                [
                    'quantity'    => $row->total,
                    'period_end'  => $periodEnd,
                    'status'      => 'open',
                ]
            );
        }

        Capsule::table('resource_metrics')
            ->where('sample_at', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->delete();
    }

    private function mapMetricToMeter(string $metric): ?string
    {
        return match ($metric) {
            'bw_out_gb', 'cdn_bandwidth_gb' => 'bandwidth_gb',
            'storage_used_gb'  => 'storage_gb_hour',
            'disk_io_read', 'disk_io_write' => 'disk_io_million_ops',
            'storage_requests', 'cdn_requests' => 'million_requests',
            default => null,
        };
    }
}
