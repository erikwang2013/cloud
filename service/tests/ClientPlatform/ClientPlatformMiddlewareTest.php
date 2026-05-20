<?php

namespace Tests\ClientPlatform;

use Common\ClientPlatform\Middleware\ClientPlatformMiddleware;
use PHPUnit\Framework\TestCase;

final class ClientPlatformMiddlewareTest extends TestCase
{
    private ClientPlatformMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ClientPlatformMiddleware();
    }

    private function createRequest(string $path, string $platform = '')
    {
        return new class($path, $platform) {
            public array $properties = [];
            private string $path;
            private string $platform;
            public function __construct(string $path, string $platform) {
                $this->path     = $path;
                $this->platform = $platform;
            }
            public function path(): string { return $this->path; }
            public function header(string $name, $default = null) {
                return $this->platform ?: $default;
            }
        };
    }

    private function decodeResponse($response): array
    {
        if (is_string($response)) return json_decode($response, true) ?? [];
        if (method_exists($response, 'rawBody')) return json_decode($response->rawBody(), true) ?? [];
        return [];
    }

    // ── Valid platform tests ──

    public function testValidPlatformPassesThrough(): void
    {
        $req        = $this->createRequest('/api/products', 'macos');
        $nextCalled = false;

        $result = $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertTrue($nextCalled);
        $this->assertSame('macos', $req->properties['client_platform'] ?? null);
        $this->assertSame('macos', $result->getHeader('X-Client-Platform'));
    }

    public function testAllSupportedPlatforms(): void
    {
        $platforms = ['ipados', 'macos', 'windows', 'linux', 'ios', 'android', 'harmonyos', 'web'];

        foreach ($platforms as $platform) {
            $req = $this->createRequest('/api/products', $platform);
            $this->middleware->process($req, fn($r) => response('ok'));
            $this->assertSame($platform, $req->properties['client_platform'] ?? null, "Failed for platform: $platform");
        }
    }

    public function testPlatformCaseInsensitive(): void
    {
        $req = $this->createRequest('/api/products', 'MacOS');
        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('macos', $req->properties['client_platform'] ?? null);
    }

    public function testPlatformWithWhitespaceIsTrimmed(): void
    {
        $req = $this->createRequest('/api/products', '  iOS  ');
        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('ios', $req->properties['client_platform'] ?? null);
    }

    // ── Missing / default header tests ──

    public function testMissingHeaderDefaultsToUnknown(): void
    {
        $req = $this->createRequest('/api/products', '');
        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('unknown', $req->properties['client_platform'] ?? null);
    }

    public function testResponseEchoesUnknownWhenMissing(): void
    {
        $req    = $this->createRequest('/api/products', '');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('unknown', $result->getHeader('X-Client-Platform'));
    }

    // ── Invalid platform tests ──

    public function testUnsupportedPlatformReturns400(): void
    {
        $req        = $this->createRequest('/api/auth/login', 'blackberry');
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

    public function testInvalidPlatformResponseIncludesHeader(): void
    {
        $req    = $this->createRequest('/api/auth/login', 'ps5');
        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('ps5', $result->getHeader('X-Client-Platform'));
        $this->assertSame(400, $result->getStatusCode());
    }

    // ── Non-API path skip tests ──

    public function testNonApiRouteSkipsValidation(): void
    {
        $req = new class {
            public array $properties = [];
            public function path(): string { return '/health'; }
            public function header(string $name, $default = null) { return 'blackberry'; }
        };
        $nextCalled = false;

        $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertTrue($nextCalled);
        $this->assertArrayNotHasKey('client_platform', $req->properties);
    }

    public function testNonApiRouteWithInvalidPlatformPasses(): void
    {
        $req = new class {
            public array $properties = [];
            public function path(): string { return '/health'; }
            public function header(string $name, $default = null) { return 'invalid_platform'; }
        };

        $result = $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame(200, $result->getStatusCode());
        $this->assertArrayNotHasKey('client_platform', $req->properties);
    }

    // ── Admin API route tests ──

    public function testAdminApiRouteIsValidated(): void
    {
        $req = $this->createRequest('/admin/api/dashboard', 'windows');
        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('windows', $req->properties['client_platform'] ?? null);
    }

    public function testAdminApiRouteRejectsInvalidPlatform(): void
    {
        $req        = $this->createRequest('/admin/api/users', 'xbox');
        $nextCalled = false;

        $result = $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertFalse($nextCalled);
        $this->assertSame(400, $result->getStatusCode());
    }

    // ── Platform propagated to next handler ──

    public function testPlatformAvailableToNextHandler(): void
    {
        $req        = $this->createRequest('/api/orders', 'android');
        $capturedPlatform = null;

        $this->middleware->process($req, function ($r) use (&$capturedPlatform) {
            $capturedPlatform = $r->properties['client_platform'] ?? null;
            return response('ok');
        });

        $this->assertSame('android', $capturedPlatform);
    }
}
