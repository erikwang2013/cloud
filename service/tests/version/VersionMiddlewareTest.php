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

    private function createRequest(string $path)
    {
        return new class($path) {
            public array $properties = [];
            private string $path;
            public function __construct(string $path) {
                $this->path = $path;
            }
            public function path(): string { return $this->path; }
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
        $req       = $this->createRequest('/api/v1/auth/login');
        $nextCalled = false;

        $result = $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertTrue($nextCalled);
        $this->assertSame('v1', $req->properties['api_version'] ?? null);
    }

    public function testUnsupportedVersionReturns400(): void
    {
        $req        = $this->createRequest('/api/v5/auth/login');
        $nextCalled = false;

        $result = $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertFalse($nextCalled);
        $body = $this->decodeResponse($result);
        $this->assertEquals(400, $body['code'] ?? 0);
        $this->assertStringContainsString('Unsupported', $body['message'] ?? '');
        $this->assertEquals(400, $result->getStatusCode());
    }

    public function testNonApiRouteSkipsValidation(): void
    {
        $req = $this->createRequest('/health');
        $nextCalled = false;

        $this->middleware->process($req, function ($r) use (&$nextCalled) {
            $nextCalled = true;
            return response('ok');
        });

        $this->assertTrue($nextCalled);
        $this->assertArrayNotHasKey('api_version', $req->properties);
    }

    public function testGraphqlWithoutApiPrefixSkipsValidation(): void
    {
        $req = $this->createRequest('/graphql');
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
        $req = $this->createRequest('/admin/api/v1/dashboard');
        $this->middleware->process($req, fn($r) => response('ok'));
        $this->assertSame('v1', $req->properties['api_version'] ?? null);
    }
}
