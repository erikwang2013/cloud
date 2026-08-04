<?php

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $limits = config('security.rate_limits');
        if (empty($limits)) {
            return $handler($request);
        }

        $path = $request->path();
        $route = 'default';
        if (str_contains($path, '/login')) {
            $route = 'login';
        }

        $limit = $limits[$route] ?? $limits['default'];

        $key = 'ratelimit:' . $request->getRealIp() . ':' . $route;

        try {
            $redis = new \Redis();
            $redis->connect(
                getenv('REDIS_HOST') ?: '127.0.0.1',
                (int)(getenv('REDIS_PORT') ?: 6379)
            );
            if (getenv('REDIS_PASSWORD')) {
                $redis->auth(getenv('REDIS_PASSWORD'));
            }

            $current = $redis->get($key) ?: 0;

            if ($current >= $limit['rate']) {
                return new Response(429, [
                    'Content-Type' => 'application/json',
                    'Retry-After' => (string)$limit['per'],
                ], json_encode([
                    'code' => 429,
                    'message' => 'Too Many Requests',
                    'data' => ['retry_after' => $limit['per']],
                ]));
            }

            if ($current == 0) {
                $redis->setex($key, $limit['per'], 1);
            } else {
                $redis->incr($key);
            }
            $redis->close();
        } catch (\Exception $e) {
            // Redis unavailable — allow request through
        }

        return $handler($request);
    }
}
