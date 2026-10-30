<?php
namespace Common\metrics;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class MetricsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!\Common\feature\FeatureFlags::isEnabled('prometheus_metrics')) {
            return $handler($request);
        }

        $start = microtime(true);

        $response = $handler($request);

        $route = self::routeLabel($request);
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

    /**
     * 优先用路由模板（/products/{id}）；未注册路由时归一化动态段，
     * 避免每个订单/资源 URL 生成新 series。
     */
    private static function routeLabel(Request $request): string
    {
        $route = $request->route;
        if ($route instanceof \Webman\Route\Route) {
            return $route->getPath();
        }
        return self::normalizePath($request->path());
    }

    public static function normalizePath(string $path): string
    {
        return preg_replace(['#/\d+#', '#/[0-9A-Za-z]{12,}#'], '/:id', $path) ?? $path;
    }
}
