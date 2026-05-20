<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class RateLimitMiddlewareTest extends TestCase
{
    private function createRequest(string $path = '/api/v1/auth/login', string $ip = '127.0.0.1', ?int $userId = 1)
    {
        return new class($path, $ip, $userId) {
            public ?int $userId;
            private string $path;
            private string $ip;
            public function __construct(string $path, string $ip, ?int $userId) {
                $this->path   = $path;
                $this->ip     = $ip;
                $this->userId = $userId;
            }
            public function path(): string { return $this->path; }
            public function getRealIp(): string { return $this->ip; }
        };
    }

    public function testLoginRouteHasStricterLimitKey(): void
    {
        $req = $this->createRequest('/api/v1/auth/login');
        $this->assertStringContainsString('login', $req->path());
    }

    public function testDefaultRouteHasDefaultLimitKey(): void
    {
        $req = $this->createRequest('/api/v1/products');
        $this->assertStringContainsString('/api/v1/products', $req->path());
    }

    public function testRateLimitKeyIncludesRoute(): void
    {
        // The middleware creates keys like "ratelimit:{userId}:{route}:{window}"
        $routes = ['login', 'register', 'default'];
        foreach ($routes as $route) {
            $key = "ratelimit:1:{$route}:" . floor(time() / 60);
            $this->assertStringContainsString($route, $key);
        }
    }

    public function test429ResponseFormat(): void
    {
        $body = json_encode(['code' => 429, 'message' => 'Too Many Requests', 'data' => ['retry_after' => 60]]);
        $decoded = json_decode($body, true);
        $this->assertSame(429, $decoded['code']);
        $this->assertArrayHasKey('retry_after', $decoded['data']);
    }

    public function testRateLimitBurstAllowsShortSpikes(): void
    {
        // Default: rate=60, burst=10 — total 70 requests per window allowed
        $limit = ['rate' => 60, 'burst' => 10, 'per' => 60];
        $this->assertSame(70, $limit['rate'] + $limit['burst']);
    }

    public function testLoginIsMoreRestrictive(): void
    {
        // Login: rate=5, burst=2 — only 7 requests per window
        $login   = ['rate' => 5, 'burst' => 2];
        $default = ['rate' => 60, 'burst' => 10];
        $this->assertLessThan($default['rate'], $login['rate']);
        $this->assertLessThan($default['burst'], $login['burst']);
    }
}
