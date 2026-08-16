<?php

namespace Tests\Security;

use Common\Security\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Webman\Http\Response;

final class RateLimitMiddlewareTest extends TestCase
{
    private function makeMiddleware(\stdClass $state, array $limits): RateLimitMiddleware
    {
        return new class($state, $limits) extends RateLimitMiddleware {
            public function __construct(private \stdClass $state, private array $limits)
            {
                $this->state->counters = $this->state->counters ?? [];
                $this->state->expires  = $this->state->expires ?? [];
            }

            protected function limits(): array
            {
                return $this->limits;
            }

            protected function redisIncr(string $key): int
            {
                $this->state->counters[$key] = ($this->state->counters[$key] ?? 0) + 1;
                return $this->state->counters[$key];
            }

            protected function redisExpire(string $key, int $ttl): void
            {
                $this->state->expires[$key] = $ttl;
            }

            protected function redisPttl(string $key): int
            {
                return ($this->state->expires[$key] ?? 60) * 1000;
            }
        };
    }

    private function request(string $path, string $ip = '10.0.0.1', ?string $token = null): object
    {
        return new class($path, $ip, $token) {
            public function __construct(private string $path, private string $ip, private ?string $token)
            {
            }

            public function path(): string
            {
                return $this->path;
            }

            public function getRealIp(): string
            {
                return $this->ip;
            }

            public function header(string $name, mixed $default = null): mixed
            {
                return $this->token === null ? $default : "Bearer {$this->token}";
            }
        };
    }

    private function limits(): array
    {
        // default: rate 3 + burst 2 = 容量 5；login/graphql 独立规则
        return [
            'default' => ['rate' => 3,  'burst' => 2, 'per' => 60],
            'login'   => ['rate' => 5,  'burst' => 0, 'per' => 60],
            'graphql' => ['rate' => 30, 'burst' => 5, 'per' => 60],
        ];
    }

    private function runProcess(RateLimitMiddleware $mw, object $req, ?callable $next = null): array
    {
        $nextCalled = false;
        $next ??= function () use (&$nextCalled) {
            $nextCalled = true;
            return 'next-result';
        };
        $result = $mw->process($req, $next);
        return [$result, $nextCalled];
    }

    public function testAllowsUpToRatePlusBurstPerIp(): void
    {
        $mw = $this->makeMiddleware(new \stdClass(), $this->limits());
        for ($i = 0; $i < 5; $i++) {
            [$result, $nextCalled] = $this->runProcess($mw, $this->request('/api/products'));
            $this->assertSame('next-result', $result, "request #{$i} should pass");
            $this->assertTrue($nextCalled);
        }
    }

    public function testBlocksSixthRequestPerIpWith429(): void
    {
        $mw = $this->makeMiddleware(new \stdClass(), $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw, $this->request('/api/products'));
        }
        [$result, $nextCalled] = $this->runProcess($mw, $this->request('/api/products'));
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(429, $result->getStatusCode());
        $this->assertFalse($nextCalled);
    }

    public function test429CarriesRetryAfterHeaderAndBody(): void
    {
        $mw = $this->makeMiddleware(new \stdClass(), $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw, $this->request('/api/products'));
        }
        [$result] = $this->runProcess($mw, $this->request('/api/products'));

        $this->assertSame('60', $result->getHeader('Retry-After'));
        $body = json_decode($result->rawBody(), true);
        $this->assertSame(429, $body['code']);
        $this->assertSame('Too Many Requests', $body['message']);
        $this->assertSame(60, $body['data']['retry_after']);
    }

    public function testPerTokenBucketSharedAcrossIps(): void
    {
        $mw = $this->makeMiddleware(new \stdClass(), $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw, $this->request('/api/orders', "10.0.0.{$i}", 'shared-token'));
        }
        // 同 token、不同 IP：token 桶已满 → 429
        [$result] = $this->runProcess($mw, $this->request('/api/orders', '10.0.0.99', 'shared-token'));
        $this->assertSame(429, $result->getStatusCode());

        // 不同 token、不同 IP：互不影响 → 放行
        $mw2 = $this->makeMiddleware(new \stdClass(), $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw2, $this->request('/api/orders', '10.0.0.1', 'token-a'));
        }
        [$result2] = $this->runProcess($mw2, $this->request('/api/orders', '10.0.0.2', 'token-b'));
        $this->assertSame('next-result', $result2);
    }

    public function testTokenKeyIsHashedNotPlaintext(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        $this->runProcess($mw, $this->request('/api/orders', '10.0.0.1', 'plain-secret-token'));

        $keys = implode(',', array_keys($state->counters));
        $this->assertStringContainsString(hash('sha256', 'plain-secret-token'), $keys);
        $this->assertStringNotContainsString('plain-secret-token', $keys);
    }

    public function testGraphqlRouteUsesGraphqlRule(): void
    {
        $mw = $this->makeMiddleware(new \stdClass(), $this->limits());
        for ($i = 0; $i < 35; $i++) {
            [$result, $nextCalled] = $this->runProcess($mw, $this->request('/graphql'));
            $this->assertSame('next-result', $result, "graphql request #{$i} should pass");
            $this->assertTrue($nextCalled);
        }
        [$result] = $this->runProcess($mw, $this->request('/graphql'));
        $this->assertSame(429, $result->getStatusCode());
        $this->assertSame('60', $result->getHeader('Retry-After'));
    }

    public function testGraphqlRuleKeyUsesGraphqlRuleName(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        $this->runProcess($mw, $this->request('/graphql'));
        $keys = implode(',', array_keys($state->counters));
        $this->assertStringContainsString(':graphql', $keys);
    }

    public function testLoginUsesStricterLoginRule(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        for ($i = 0; $i < 5; $i++) {
            [$result] = $this->runProcess($mw, $this->request('/api/auth/login'));
            $this->assertSame('next-result', $result);
        }
        [$result] = $this->runProcess($mw, $this->request('/api/auth/login'));
        $this->assertSame(429, $result->getStatusCode());
        $this->assertStringContainsString(':login', implode(',', array_keys($state->counters)));
    }

    public function testFailOpenWhenRedisUnavailable(): void
    {
        $middleware = new class extends RateLimitMiddleware {
            protected function limits(): array
            {
                return ['default' => ['rate' => 1, 'burst' => 0, 'per' => 60]];
            }

            protected function redisIncr(string $key): int
            {
                throw new \RuntimeException('Redis down');
            }
        };
        [$result, $nextCalled] = $this->runProcess($middleware, $this->request('/api/products'));
        $this->assertSame('next-result', $result);
        $this->assertTrue($nextCalled);
    }

    public function testAnonymousRequestUsesIpBucketOnly(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        [$result] = $this->runProcess($mw, $this->request('/api/products', '10.0.0.7'));
        $this->assertSame('next-result', $result);
        $this->assertStringContainsString('ratelimit:ip:10.0.0.7:', implode(',', array_keys($state->counters)));
        $this->assertStringNotContainsString('ratelimit:tok:', implode(',', array_keys($state->counters)));
    }

    public function testHealthPathsAreExemptFromCounting(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw, $this->request('/api/products'));
        }
        // 桶已满后 /health 仍放行
        [$result, $nextCalled] = $this->runProcess($mw, $this->request('/health'));
        $this->assertSame('next-result', $result);
        $this->assertTrue($nextCalled);

        foreach (['/health', '/health/live', '/health/ready', '/health/deps'] as $path) {
            $state2 = new \stdClass();
            $mw2 = $this->makeMiddleware($state2, $this->limits());
            $this->runProcess($mw2, $this->request($path));
            $this->assertSame([], array_keys($state2->counters), "{$path} must not consume a bucket");
        }
    }

    public function testStripeWebhookIsExemptFromCounting(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw, $this->request('/api/products'));
        }
        // 桶已满后 webhook 仍放行且不计数
        [$result, $nextCalled] = $this->runProcess($mw, $this->request('/api/payments/webhook/stripe'));
        $this->assertSame('next-result', $result);
        $this->assertTrue($nextCalled);
        $this->assertStringNotContainsString('webhook', implode(',', array_keys($state->counters)));
    }

    public function testRetryAfterTakesMaxOfBothBuckets(): void
    {
        $state = new \stdClass();
        $mw = $this->makeMiddleware($state, $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw, $this->request('/api/orders', '10.0.0.1', 'shared-token'));
        }
        // IP 桶剩 2s、token 桶剩 60s → Retry-After 取 max = 60
        $state->expires['ratelimit:ip:10.0.0.1:default'] = 2;
        [$result] = $this->runProcess($mw, $this->request('/api/orders', '10.0.0.1', 'shared-token'));
        $this->assertSame(429, $result->getStatusCode());
        $this->assertSame('60', $result->getHeader('Retry-After'));

        // 仅 token 桶超限（IP 换新）→ Retry-After = token 桶剩余
        $state2 = new \stdClass();
        $mw2 = $this->makeMiddleware($state2, $this->limits());
        for ($i = 0; $i < 5; $i++) {
            $this->runProcess($mw2, $this->request('/api/orders', "10.0.0.{$i}", 'shared-token'));
        }
        $state2->expires['ratelimit:tok:' . hash('sha256', 'shared-token') . ':default'] = 2;
        [$result2] = $this->runProcess($mw2, $this->request('/api/orders', '10.0.0.99', 'shared-token'));
        $this->assertSame(429, $result2->getStatusCode());
        $this->assertSame('2', $result2->getHeader('Retry-After'));
    }
}
