<?php
namespace Common\Security;

use Common\Helper\Response;

class RateLimitMiddleware
{
    public function process($request, callable $next, string $route = 'default')
    {
        $limits = config('security.rate_limits');
        $limit = $limits[$route] ?? $limits['default'];

        $key = "ratelimit:" . $request->getRealIp() . ":{$route}";

        try {
            $current = \Redis::get($key) ?: 0;

            if ($current >= $limit['rate']) {
                return json(Response::error(429, 'Too Many Requests', [
                    'retry_after' => $limit['per'],
                ]));
            }

            if ($current == 0) {
                \Redis::setex($key, $limit['per'], 1);
            } else {
                \Redis::incr($key);
            }
        } catch (\Exception $e) {
            // Redis unavailable — allow request through
        }

        return $next($request);
    }
}
