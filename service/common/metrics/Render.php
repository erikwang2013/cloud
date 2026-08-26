<?php
namespace Common\metrics;

class Render
{
    public static function text(): string
    {
        $data = Collector::getAll();
        $lines = [];

        foreach ($data as $key => $value) {
            if (str_ends_with($key, ':samples')) {
                $baseKey = substr($key, 0, -8);
                $lines[] = "# HELP {$baseKey} Histogram metric";
                $lines[] = "# TYPE {$baseKey} histogram";
                $samples = array_map('floatval', $value);
                $count = count($samples);
                $sum = array_sum($samples);
                $lines[] = "{$baseKey}_count {$count}";
                $lines[] = "{$baseKey}_sum {$sum}";
                if ($count > 0) {
                    sort($samples);
                    $p50 = $samples[(int) ($count * 0.5)] ?? end($samples);
                    $p95 = $samples[(int) ($count * 0.95)] ?? end($samples);
                    $p99 = $samples[(int) ($count * 0.99)] ?? end($samples);
                    $lines[] = "{$baseKey}{quantile=\"0.5\"} {$p50}";
                    $lines[] = "{$baseKey}{quantile=\"0.95\"} {$p95}";
                    $lines[] = "{$baseKey}{quantile=\"0.99\"} {$p99}";
                }
            } elseif (is_numeric($value)) {
                if (!str_contains($key, '{')) {
                    $lines[] = "# HELP {$key} Counter/Gauge metric";
                    $lines[] = "# TYPE {$key} gauge";
                }
                $lines[] = "{$key} {$value}";
            }
        }

        $lines[] = "# EOF";
        return implode("\n", $lines) . "\n";
    }
}
