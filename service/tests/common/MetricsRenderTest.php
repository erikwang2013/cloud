<?php

namespace Tests\common;

use Common\metrics\Collector;
use Common\metrics\MetricsMiddleware;
use Common\metrics\Render;
use PHPUnit\Framework\TestCase;

/**
 * Prometheus 文本格式：指标名与 label 必须分离（name{labels}），
 * 直方图输出 name_count / name_sum，不能出现 `}{`、`}_count` 之类非法拼接。
 */
final class MetricsRenderTest extends TestCase
{
    public function testRenderProducesValidPrometheusText(): void
    {
        $text = Render::render([
            'http_requests_total{route="/api/v1/orders/:id",method="GET",status="200"}' => '3',
            'http_request_duration_ms{route="/api/v1/orders/:id",method="GET"}:samples' => ['10.5', '20'],
            'queue_depth' => '7',
        ]);

        $this->assertStringNotContainsString('}{', $text);
        $this->assertStringNotContainsString('}_count', $text);
        $this->assertStringNotContainsString('}_sum', $text);

        $this->assertStringContainsString(
            'http_requests_total{route="/api/v1/orders/:id",method="GET",status="200"} 3',
            $text
        );
        $this->assertStringContainsString('# TYPE http_requests_total counter', $text);
        $this->assertStringContainsString(
            'http_request_duration_ms_count{route="/api/v1/orders/:id",method="GET"} 2',
            $text
        );
        $this->assertStringContainsString(
            'http_request_duration_ms_sum{route="/api/v1/orders/:id",method="GET"} 30.5',
            $text
        );
        $this->assertStringContainsString('# TYPE http_request_duration_ms summary', $text);
        $this->assertStringNotContainsString('_bucket', $text);
        $this->assertStringContainsString(
            'http_request_duration_ms{route="/api/v1/orders/:id",method="GET",quantile="0.5"}',
            $text
        );
        $this->assertStringContainsString('queue_depth 7', $text);
        // 无 label 的直方图分位数也要是合法 label 语法
        $this->assertStringContainsString('{quantile="0.95"}', Render::render([
            'latency:samples' => ['1'],
        ]));
    }

    public function testRenderDeclaresEachMetricOnceAcrossLabelSets(): void
    {
        $text = Render::render([
            'http_requests_total{route="/a",method="GET",status="200"}' => '1',
            'http_requests_total{route="/b",method="GET",status="200"}' => '2',
            'latency{route="/a"}:samples' => ['1'],
            'latency{route="/b"}:samples' => ['2'],
        ]);

        $this->assertSame(1, substr_count($text, '# TYPE http_requests_total counter'));
        $this->assertSame(1, substr_count($text, '# TYPE latency summary'));
        $this->assertSame(2, substr_count($text, '# HELP'));
    }

    /**
     * 回归：直方图只有 metrics:name{labels}:samples 键（没有基键），
     * getAll 必须直接读取 samples list，否则 /metrics 里所有直方图（含延迟分位）全部消失。
     */
    public function testCollectorKeepsHistogramSamplesAndCapsList(): void
    {
        $redis = new class extends \Redis {
            public array $lists = [];
            public array $strings = [];
            public array $trims = [];
            public int $counters = 0;

            public function __construct()
            {
            }

            public function rPush(string $key, mixed ...$elements): \Redis|int|false
            {
                foreach ($elements as $element) {
                    $this->lists[$key][] = $element;
                }
                return count($this->lists[$key]);
            }

            public function lTrim(string $key, int $start, int $end): \Redis|bool
            {
                $this->trims[] = [$key, $start, $end];
                return true;
            }

            public function lRange(string $key, int $start, int $end): \Redis|array|false
            {
                return $this->lists[$key] ?? [];
            }

            public function get(string $key): mixed
            {
                return $this->strings[$key] ?? false;
            }

            public function incrBy(string $key, int $value): \Redis|int|false
            {
                $this->counters += $value;
                $this->strings[$key] = $this->counters;
                return $this->counters;
            }

            public function scan(
                string|int|null &$iterator,
                ?string $pattern = null,
                int $count = 0,
                ?string $type = null
            ): array|false {
                if ($iterator === null) {
                    $iterator = 0;
                    return array_merge(array_keys($this->lists), array_keys($this->strings));
                }
                $iterator = 0;
                return [];
            }
        };
        Collector::setRedis($redis);

        try {
            Collector::histogram('latency', 10.0, ['route' => '/a']);
            Collector::histogram('latency', 30.0, ['route' => '/a']);
            Collector::counter('requests_total', 3, ['route' => '/a']);

            // samples 列表必须有上限，防止无限增长（每次写入后裁剪到最近 1000）
            $this->assertCount(2, $redis->trims);
            foreach ($redis->trims as $trim) {
                $this->assertSame(['metrics:latency{route="/a"}:samples', -1000, -1], $trim);
            }

            $text = Render::text();
        } finally {
            (new \ReflectionProperty(Collector::class, 'redis'))->setValue(null, null);
        }

        $this->assertStringContainsString('latency_count{route="/a"} 2', $text);
        $this->assertStringContainsString('latency_sum{route="/a"} 40', $text);
        $this->assertStringContainsString('latency{route="/a",quantile="0.5"}', $text);
        $this->assertStringContainsString('requests_total{route="/a"} 3', $text);
        $this->assertStringNotContainsString('}{', $text);
        $this->assertStringNotContainsString('}_count', $text);
    }

    public function testNormalizePathCollapsesDynamicSegments(): void
    {
        $this->assertSame('/api/v1/orders/:id', MetricsMiddleware::normalizePath('/api/v1/orders/12345'));
        $this->assertSame(
            '/api/v1/products/:id/reviews',
            MetricsMiddleware::normalizePath('/api/v1/products/aB3dE5fG7hJ9kL1m/reviews')
        );
        $this->assertSame('/api/v1/products/search', MetricsMiddleware::normalizePath('/api/v1/products/search'));
        $this->assertSame('/', MetricsMiddleware::normalizePath('/'));
    }
}
