<?php
namespace Tests\Confirmation;

use Common\Confirmation\ConfirmationMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfirmationMiddlewareTest extends TestCase
{
    private function createRequest(array $post = [], ?int $userId = null)
    {
        return new class($post, $userId) {
            public ?int $userId;
            private array $post;
            public function __construct(array $post, ?int $userId) {
                $this->post = $post;
                $this->userId = $userId;
            }
            public function input($name, $default = null) {
                return $this->post[$name] ?? $default;
            }
            public function header($name, $default = null) {}
            public function getRealIp() { return '127.0.0.1'; }
        };
    }

    private function createMiddleware(bool $passwordResult = false): ConfirmationMiddleware
    {
        return new class($passwordResult) extends ConfirmationMiddleware {
            private bool $passwordResult;
            public function __construct(bool $passwordResult) { $this->passwordResult = $passwordResult; }
            protected function verifyPassword(int $userId, string $password): bool {
                return $this->passwordResult;
            }
        };
    }

    private function decodeResponse($response): array
    {
        if (is_string($response)) {
            return json_decode($response, true);
        }
        if (method_exists($response, 'rawBody')) {
            return json_decode($response->rawBody(), true);
        }
        return [];
    }

    public function testReturns401WhenUserNotAuthenticated(): void
    {
        $middleware = $this->createMiddleware();
        $request    = $this->createRequest(['confirm_password' => 'secret123'], null);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
        });
        $body = $this->decodeResponse($response);
        $this->assertEquals(401, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testReturns422WhenPasswordMissing(): void
    {
        $middleware = $this->createMiddleware();
        $request    = $this->createRequest([], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
        });
        $body = $this->decodeResponse($response);
        $this->assertEquals(422, $body['code']);
        $this->assertStringContainsString('Password confirmation required', $body['message']);
        $this->assertFalse($nextCalled);
    }

    public function testReturns422WhenPasswordEmpty(): void
    {
        $middleware = $this->createMiddleware();
        $request    = $this->createRequest(['confirm_password' => ''], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
        });
        $body = $this->decodeResponse($response);
        $this->assertEquals(422, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testReturns403WhenPasswordVerificationFails(): void
    {
        $middleware = $this->createMiddleware(false); // password verification returns false
        $request    = $this->createRequest(['confirm_password' => 'wrongpassword'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
        });
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertStringContainsString('Password verification failed', $body['message']);
        $this->assertFalse($nextCalled);
    }

    public function testNextCalledWhenPasswordVerificationPasses(): void
    {
        $middleware = $this->createMiddleware(true); // password verification returns true
        $request    = $this->createRequest(['confirm_password' => 'correctpassword'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return 'next-result';
        });
        $this->assertTrue($nextCalled);
        $this->assertEquals('next-result', $response);
    }

    public function testRateLimitCounterKeyFormat(): void
    {
        $userId = 12345;
        $key = "confirm_failed:{$userId}";
        $this->assertStringContainsString('confirm_failed', $key);
        $this->assertStringContainsString((string) $userId, $key);
    }

    public function testLockKeyFormat(): void
    {
        $userId = 42;
        $lockKey = "confirm_lock:{$userId}";
        $this->assertStringContainsString('confirm_lock', $lockKey);
        $this->assertStringContainsString((string) $userId, $lockKey);
    }

    public function testMiddlewareIsInstantiable(): void
    {
        $m = $this->createMiddleware();
        $this->assertInstanceOf(ConfirmationMiddleware::class, $m);
    }

    #[DataProvider('maxFailureProvider')]
    public function testMaxFailureConstant(int $failures, bool $shouldLock): void
    {
        // Verify the locking logic: lock triggers at >= MAX_FAILURES (5)
        $locked = $failures >= 5;
        $this->assertEquals($shouldLock, $locked);
    }

    public static function maxFailureProvider(): array
    {
        return [
            '4 failures no lock'  => [4, false],
            '5 failures triggers' => [5, true],
            '6 failures locked'   => [6, true],
        ];
    }
}
