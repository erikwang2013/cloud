<?php
namespace Common\Security;

use Common\Helper\Response;
use Webman\Http\Response as Http;

class RateLimitMiddleware
{
    // 路由 → 限流规则名（对应 config/security.php 的 rate_limits 键）；未知路由走 default
    private const ROUTE_MAP = [
        '/api/auth/register'        => 'register',
        '/api/auth/login'           => 'login',
        '/api/auth/login/recovery'  => 'login',
        '/api/auth/refresh'         => 'login',
        '/api/auth/forgot-password' => 'password_reset',
        '/api/auth/reset-password'  => 'password_reset',
        '/api/auth/send-sms'        => 'sms',
        '/api/captcha/create'       => 'captcha',
        '/graphql'                  => 'graphql',
    ];

    // 豁免路径：不计数、不限流（nginx 层 100r/s 仍兜底）。
    // /health* = 监控探针；webhook = 签名校验 + Stripe 退避重试，限流只会丢支付事件
    private const EXEMPT_PATHS = [
        '/health',
        '/health/live',
        '/health/ready',
        '/health/deps',
        '/api/payments/webhook/stripe',
    ];

    public function process($request, callable $next)
    {
        if (in_array($request->path(), self::EXEMPT_PATHS, true)) {
            return $next($request);
        }

        $rule = $this->routeName($request->path());
        $limits = $this->limits();
        $limit = $limits[$rule] ?? $limits['default'];

        // rate=稳态配额，burst=可透支额度：固定窗口计数上限 rate+burst（设计 D2）
        $capacity = $limit['rate'] + $limit['burst'];
        $window = $limit['per'];

        try {
            // OR 语义：per-IP / per-token 双桶独立计数，任一超限即 429（防换 IP/换 token 绕过）
            $retryAfter = $this->checkBucket("ratelimit:ip:{$request->getRealIp()}:{$rule}", $capacity, $window);

            if (($token = $this->bearerToken($request)) !== null) {
                $blocked = $this->checkBucket('ratelimit:tok:' . hash('sha256', $token) . ":{$rule}", $capacity, $window);
                if ($blocked !== null) {
                    $retryAfter = max($retryAfter ?? 0, $blocked);
                }
            }

            if ($retryAfter !== null) {
                return $this->rateLimited($retryAfter);
            }
        } catch (\Exception $e) {
            // Redis 不可用 — fail-open，nginx 层粗粒度限流兜底
        }

        return $next($request);
    }

    // 返回 null=放行；否则返回窗口剩余秒数（Retry-After，PTTL 毫秒向上取整）
    private function checkBucket(string $key, int $capacity, int $window): ?int
    {
        // 原子计数：INCR 后判断，避免 GET+SET 竞态绕过限流
        $current = $this->redisIncr($key);
        if ($current === 1) {
            $this->redisExpire($key, $window);
        }
        if ($current > $capacity) {
            $pttl = $this->redisPttl($key);
            return $pttl > 0 ? max(1, (int) ceil($pttl / 1000)) : $window;
        }
        return null;
    }

    private function rateLimited(int $retryAfter): Http
    {
        return new Http(429, [
            'Content-Type' => 'application/json',
            'Retry-After'  => (string) $retryAfter,
        ], json_encode(Response::error(429, 'Too Many Requests', ['retry_after' => $retryAfter]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function bearerToken($request): ?string
    {
        $header = $request->header('authorization');
        if ($header === null) {
            return null;
        }
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    // 测试覆写点：内存计数替代真实 Redis（config/redis 依赖留在生产路径）
    protected function limits(): array
    {
        return config('security.rate_limits');
    }

    protected function redisIncr(string $key): int
    {
        return \support\Redis::incr($key);
    }

    protected function redisExpire(string $key, int $ttl): void
    {
        \support\Redis::expire($key, $ttl);
    }

    protected function redisPttl(string $key): int
    {
        return (int) \support\Redis::pttl($key);
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
        // 供应商外部 API（API Key 认证）：走 supplier_api 规则
        if (str_starts_with($path, '/api/supplier/external/')) {
            return 'supplier_api';
        }
        // 支付/上传/供应商提现：路由含动态段，需正则匹配（对应 config/security.php 的 rate_limits 键）
        if (preg_match('#^/api/orders/\d+/pay$#', $path)) {
            return 'pay';
        }
        if ($path === '/api/upload') {
            return 'upload';
        }
        if ($path === '/api/supplier/withdraw') {
            return 'supplier_withdraw';
        }
        return 'default';
    }
}
