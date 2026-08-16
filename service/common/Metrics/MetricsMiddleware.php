<?php
namespace Common\Metrics;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class MetricsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!\Common\Feature\FeatureFlags::isEnabled('prometheus_metrics')) {
            return $handler($request);
        }

        $start = microtime(true);

        $response = $handler($request);

        $route = $request->path();
        $method = $request->method();
        $status = $response->getStatusCode();

        Collector::counter('http_requests_total', 1, [
            'route'  => $route,
            'method' => $method,
            'status' => (string) $status,
        ]);

        Collector::duration('http_request_duration_ms', $start, [
            'route'  => $route,
            'method' => $method,
        ]);

        return $response;
    }
}
