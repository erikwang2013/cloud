<?php

namespace Tests\common;

use Common\security\WafMiddleware;
use PHPUnit\Framework\TestCase;

final class WafMiddlewareTest extends TestCase
{
    private function request(string $method): object
    {
        return new class($method) {
            public function __construct(private string $method)
            {
            }

            public function method(): string
            {
                return $this->method;
            }

            public function path(): string
            {
                return '/api/test';
            }

            public function queryString(): string
            {
                return '';
            }

            public function header(string $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return [];
            }
        };
    }

    // 子类覆写 readRawBody 记录调用，断言 raw body 仅在 body 型方法被读取
    private function runProcess(string $method): array
    {
        $counter = new \stdClass();
        $counter->reads = 0;

        $middleware = new class($counter) extends WafMiddleware {
            public function __construct(private \stdClass $counter)
            {
            }

            protected function readRawBody(): string
            {
                $this->counter->reads++;
                return '{"x":1}';
            }
        };

        $nextCalled = false;
        $result = $middleware->process($this->request($method), function () use (&$nextCalled) {
            $nextCalled = true;
            return 'next-result';
        });

        return [$counter->reads, $nextCalled, $result];
    }

    public function testGetSkipsRawBodyRead(): void
    {
        [$reads, $nextCalled, $result] = $this->runProcess('GET');
        $this->assertSame(0, $reads);
        $this->assertTrue($nextCalled);
        $this->assertSame('next-result', $result);
    }

    public function testPostReadsRawBody(): void
    {
        [$reads, $nextCalled] = $this->runProcess('POST');
        $this->assertSame(1, $reads);
        $this->assertTrue($nextCalled);
    }

    public function testPutPatchDeleteReadRawBody(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            [$reads, $nextCalled] = $this->runProcess($method);
            $this->assertSame(1, $reads, "raw body should be read for {$method}");
            $this->assertTrue($nextCalled, "next() should run for {$method}");
        }
    }

    public function testLowercasePostReadsRawBody(): void
    {
        [$reads] = $this->runProcess('post');
        $this->assertSame(1, $reads);
    }
}
