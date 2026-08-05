<?php
namespace Common\Security;

use Common\Helper\Response;

class RateLimitMiddleware
{
    // 公开敏感端点 → 限流规则名（对应 config/security.php 的 rate_limits 键）
    private const ROUTE_MAP = [
        '/api/auth/register'        => 'register',
        '/api/auth/login'           => 'login',
        '/api/auth/login/recovery'  => 'login',
        '/api/auth/refresh'         => 'login',
        '/api/auth/forgot-password' => 'password_reset',
        '/api/auth/reset-password'  => 'password_reset',
        '/api/auth/send-sms'        => 'sms',
        '/api/captcha/create'       => 'captcha',
    ];

    public function process($request, callable $next)
    {
        $route = $this->routeName($request->path());
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

    private function routeName(string $path): string
    {
        if (isset(self::ROUTE_MAP[$path])) {
            return self::ROUTE_MAP[$path];
        }
        // OAuth：/api/auth/{provider} 与 /api/auth/{provider}/callback
        if (preg_match('#^/api/auth/[a-z]+(/callback)?$#', $path)) {
            return 'oauth';
        }
        return 'default';
    }
}
