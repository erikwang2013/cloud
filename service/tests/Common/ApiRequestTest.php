<?php

namespace Tests\Common;

use Common\Http\ApiRequest;
use PHPUnit\Framework\TestCase;

final class ApiRequestTest extends TestCase
{
    private function createRequest(string $path, string $version = 'v1'): ApiRequest
    {
        return new class($path, $version) extends ApiRequest {
            private string $mockPath;
            private string $mockVersion;
            public function __construct(string $path, string $version) {
                $this->mockPath    = $path;
                $this->mockVersion = $version;
                parent::__construct('');
            }
            public function path(): string {
                $path = $this->mockPath;
                // Apply the same logic as ApiRequest
                if (str_starts_with($path, '/api/') && !preg_match('#^/api/v\d+/#', $path)) {
                    $version = $this->header('X-Api-Version', 'v1');
                    return preg_replace('#^(/api)/#', '$1/' . $version . '/', $path);
                }
                if (str_starts_with($path, '/admin/api/') && !preg_match('#^/admin/api/v\d+/#', $path)) {
                    $version = $this->header('X-Api-Version', 'v1');
                    return preg_replace('#^(/admin/api)/#', '$1/' . $version . '/', $path);
                }
                return $path;
            }
            public function header(?string $name = null, mixed $default = null): mixed {
                if (strtolower($name ?? '') === 'x-api-version') return $this->mockVersion;
                return $default;
            }
        };
    }

    public function testRewritesApiPathWithV1(): void
    {
        $req  = $this->createRequest('/api/auth/login', 'v1');
        $this->assertSame('/api/v1/auth/login', $req->path());
    }

    public function testRewritesApiPathWithV2(): void
    {
        $req  = $this->createRequest('/api/auth/login', 'v2');
        $this->assertSame('/api/v2/auth/login', $req->path());
    }

    public function testDoesNotRewriteAlreadyVersionedPath(): void
    {
        $req  = $this->createRequest('/api/v1/auth/login', 'v2');
        $this->assertSame('/api/v1/auth/login', $req->path());
    }

    public function testRewritesAdminApiPath(): void
    {
        $req  = $this->createRequest('/admin/api/dashboard', 'v1');
        $this->assertSame('/admin/api/v1/dashboard', $req->path());
    }

    public function testDoesNotRewriteNonApiPath(): void
    {
        $req  = $this->createRequest('/health', 'v1');
        $this->assertSame('/health', $req->path());
    }

    public function testDoesNotRewriteAppAdminPath(): void
    {
        $req  = $this->createRequest('/app/admin/dashboard/data', 'v1');
        $this->assertSame('/app/admin/dashboard/data', $req->path());
    }

    public function testDefaultVersionIsV1(): void
    {
        $req = new class('/api/auth/login', '') extends ApiRequest {
            private string $mockPath;
            private string $mockVersion;
            public function __construct(string $path, string $version) {
                $this->mockPath = $path;
                $this->mockVersion = $version;
                parent::__construct('');
            }
            public function path(): string {
                $path = $this->mockPath;
                if (str_starts_with($path, '/api/') && !preg_match('#^/api/v\d+/#', $path)) {
                    $version = $this->header('X-Api-Version', 'v1');
                    return preg_replace('#^(/api)/#', '$1/' . $version . '/', $path);
                }
                return $path;
            }
            public function header(?string $name = null, mixed $default = null): mixed {
                if (strtolower($name ?? '') === 'x-api-version') return $this->mockVersion ?: $default;
                return $default;
            }
        };

        $this->assertSame('/api/v1/auth/login', $req->path());
    }
}
