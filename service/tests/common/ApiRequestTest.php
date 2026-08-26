<?php

namespace Tests\common;

use Common\http\ApiRequest;
use PHPUnit\Framework\TestCase;

final class ApiRequestTest extends TestCase
{
    private function createRequest(string $path): ApiRequest
    {
        return new class($path) extends ApiRequest {
            private string $mockPath;
            public function __construct(string $path) {
                $this->mockPath = $path;
                parent::__construct('');
            }
            public function path(): string {
                return $this->mockPath;
            }
        };
    }

    public function testKeepsApiPathAsIs(): void
    {
        $req = $this->createRequest('/api/auth/login');
        $this->assertSame('/api/auth/login', $req->path());
    }

    public function testKeepsAdminApiPathAsIs(): void
    {
        $req = $this->createRequest('/admin/api/dashboard');
        $this->assertSame('/admin/api/dashboard', $req->path());
    }

    public function testKeepsNonApiPathAsIs(): void
    {
        $req = $this->createRequest('/health');
        $this->assertSame('/health', $req->path());
    }

    public function testKeepsAppAdminPathAsIs(): void
    {
        $req = $this->createRequest('/app/admin/dashboard/data');
        $this->assertSame('/app/admin/dashboard/data', $req->path());
    }
}
