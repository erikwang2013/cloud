<?php
namespace Common\metrics;

class Render
{
    public static function text(): string
    {
        return self::render(Collector::getAll());
    }

    /**
     * 输出合法 Prometheus 文本格式：指标名与 label 分离为 name{labels}，
     * 直方图为 name_count/name_sum，分位数作为独立 label。
     */
    public static function render(array $data): string
    {
        $lines = [];
        $declared = [];

        foreach ($data as $key => $value) {
            if (str_ends_with($key, ':samples')) {
                [$name, $labels] = self::split(substr($key, 0, -8));
                if (!isset($declared[$name])) {
                    // 当前输出的是 quantile + _count/_sum，语义是 summary；histogram 需要 _bucket
                    $lines[] = "# HELP {$name} Summary metric";
                    $lines[] = "# TYPE {$name} summary";
                    $declared[$name] = true;
                }

                $samples = array_map('floatval', (array) $value);
                $count = count($samples);
                $sum = array_sum($samples);
                $lines[] = "{$name}_count{$labels} {$count}";
                $lines[] = "{$name}_sum{$labels} {$sum}";

                if ($count > 0) {
                    sort($samples);
                    foreach ([0.5, 0.95, 0.99] as $quantile) {
                        $sample = $samples[(int) ($count * $quantile)] ?? end($samples);
                        $lines[] = "{$name}" . self::withLabel($labels, 'quantile', (string) $quantile) . " {$sample}";
                    }
                }
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            [$name, $labels] = self::split($key);
            if (!isset($declared[$name])) {
                $type = str_ends_with($name, '_total') ? 'counter' : 'gauge';
                $lines[] = "# HELP {$name} {$type} metric";
                $lines[] = "# TYPE {$name} {$type}";
                $declared[$name] = true;
            }
            $lines[] = "{$name}{$labels} {$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return array{0: string, 1: string} [metric name, label block incl. braces or ''] */
    private static function split(string $key): array
    {
        $pos = strpos($key, '{');
        if ($pos === false) {
            return [$key, ''];
        }
        return [substr($key, 0, $pos), substr($key, $pos)];
    }

    private static function withLabel(string $labels, string $label, string $value): string
    {
        if ($labels === '' || $labels === '{}') {
            return '{' . $label . '="' . $value . '"}';
        }
        return substr($labels, 0, -1) . ',' . $label . '="' . $value . '"}';
    }
}
