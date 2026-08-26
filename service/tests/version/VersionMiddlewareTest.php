<?php

namespace Tests\version;

use Common\version\middleware\VersionMiddleware;
use PHPUnit\Framework\TestCase;

final class VersionMiddlewareTest extends TestCase
{
    private VersionMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VersionMiddleware();
    }

    private function createRequest(string $path, string $version = 'v1')
    {
        return new class($path, $version) {
            public array $properties = [];
            private string $path;
            private string $version;
            public function __construct(string $path, string $version) {
                $this->path    = $path;
                $this->version = $version;
            }
            public function path(): string { return $this->path; }
            public function header(string $name, $default = null) {
                return $this->version ?: $default;
            }
        };
    }

    private function decodeResponse($response): array
    {
        if (is_string($response)) return json_decode($response, true) ?? [];
        if (method_exists($response, 'rawBody')) return json_decode($response->rawBody(), true) ?? [];
        return [];
    }

    public function testValidVersionPassesThrough(): void
    {
        $req       = $this->createRequest('/api/v1/auth/login', 'v1');
        $nextCalled = false;

        $result = $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertTrue($nextCalled);
        $this->assertSame('v1', $req->properties['api_version'] ?? null);
        $this->assertSame('v1', $result->getHeader('X-Api-Version'));
    }

    public function testMissingVersionDefaultsToV1(): void
    {
        $req = new class {
            public array $properties = [];
            public function path(): string { return '/api/v1/auth/login'; }
            public function header(string $name, $default = null) { return $default; }
        };

        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('v1', $req->properties['api_version'] ?? null);
    }

    public function testUnsupportedVersionReturns400(): void
    {
        $req      = $this->createRequest('/api/v1/auth/login', 'v5');
        $nextCalled = false;

        $result = $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertFalse($nextCalled);
        $body = $this->decodeResponse($result);
        $this->assertEquals(400, $body['code'] ?? 0);
        $this->assertStringContainsString('Unsupported', $body['message'] ?? '');
    }

    public function testNonApiRouteSkipsValidation(): void
    {
        $req = new class {
            public array $properties = [];
            public function path(): string { return '/health'; }
            public function header(string $name, $default = null) { return 'v5'; }
        };
        $nextCalled = false;

        $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertTrue($nextCalled);
        $this->assertArrayNotHasKey('api_version', $req->properties);
    }

    public function testAdminApiRouteIsValidated(): void
    {
        $req = $this->createRequest('/admin/api/v1/dashboard', 'v1');
        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('v1', $req->properties['api_version'] ?? null);
    }

    public function testErrorResponseIncludesVersionHeader(): void
    {
        $req    = $this->createRequest('/api/v1/auth/login', 'v99');
        $result = $this->middleware->process($req, fn($r) => response('ok'));

        $this->assertSame('v99', $result->getHeader('X-Api-Version'));
        $this->assertSame(400, $result->getStatusCode());
    }
}
