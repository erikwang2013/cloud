<?php

namespace Tests\common;

use Common\security\InternalTokenRequiredMiddleware;
use PHPUnit\Framework\TestCase;
use Tests\support\RequestMock;
use Webman\Http\Response;

/**
 * /internal/v1 强制令牌：nginx 全量反代下公网请求来源均为 127.0.0.1，
 * 因此不得存在 loopback 旁路；令牌缺失/为空/错误一律 403。
 */
final class InternalTokenRequiredMiddlewareTest extends TestCase
{
    private string|false $originalToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalToken = getenv('INTERNAL_MONITOR_TOKEN');
    }

    protected function tearDown(): void
    {
        $this->restoreToken();
        parent::tearDown();
    }

    public function testCorrectTokenPasses(): void
    {
        putenv('INTERNAL_MONITOR_TOKEN=secret');

        $response = $this->handle('secret');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passed', $response->rawBody());
    }

    public function testMissingTokenRejectedEvenFromLoopback(): void
    {
        putenv('INTERNAL_MONITOR_TOKEN=secret');

        // RequestMock 构造时不带连接，middleware 若尝试 loopback 放行会因无 remote IP 失败
        $response = $this->handle(null);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('requires valid token', $response->rawBody());
    }

    public function testWrongTokenRejected(): void
    {
        putenv('INTERNAL_MONITOR_TOKEN=secret');

        $response = $this->handle('wrong');

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEmptyServerTokenRejectsEverything(): void
    {
        putenv('INTERNAL_MONITOR_TOKEN');

        // 服务端未配置令牌时必须 fail closed，空头不得通过
        $response = $this->handle('');

        $this->assertSame(403, $response->getStatusCode());
    }

    private function handle(?string $token): Response
    {
        $request = new class([], []) extends RequestMock {
            private array $headers = [];

            public function withHeader(string $name, string $value): self
            {
                $this->headers[strtolower($name)] = $value;
                return $this;
            }

            public function header(?string $name = null, mixed $default = null): mixed
            {
                if ($name === null) {
                    return $this->headers;
                }
                return $this->headers[strtolower($name)] ?? $default;
            }
        };

        if ($token !== null) {
            $request->withHeader('X-Internal-Token', $token);
        }

        return (new InternalTokenRequiredMiddleware())->process(
            $request,
            fn () => new Response(200, [], 'passed')
        );
    }

    private function restoreToken(): void
    {
        if ($this->originalToken === false) {
            putenv('INTERNAL_MONITOR_TOKEN');
            return;
        }
        putenv('INTERNAL_MONITOR_TOKEN=' . $this->originalToken);
    }
}
