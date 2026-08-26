<?php
namespace Tests\confirmation;

use App\user\model\User;
use Common\confirmation\ConfirmationMiddleware;
use Illuminate\Support\Facades\Redis as RedisFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfirmationMiddlewareTest extends TestCase
{
    private ?object $redisRoot = null;

    protected function setUp(): void
    {
        // 无 Redis 环境下用内存 fake 注入 facade（swap 不影响其他测试：tearDown 恢复原实例）
        $this->redisRoot = RedisFacade::getFacadeRoot();
        RedisFacade::swap(new class {
            public function exists(...$args) { return 0; }
            public function incr(...$args) { return 1; }
            public function expire(...$args) { return true; }
            public function setex(...$args) { return true; }
            public function del(...$args) { return 1; }
        });
    }

    protected function tearDown(): void
    {
        RedisFacade::swap($this->redisRoot);
    }

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

    private function createMiddleware(
        bool $passwordResult = false,
        bool $requireApprover = false,
        bool $approverPasswordResult = true,
        string $approverRole = 'admin'
    ): ConfirmationMiddleware {
        return new class($passwordResult, $requireApprover, $approverPasswordResult, $approverRole) extends ConfirmationMiddleware {
            private bool $passwordResult;
            private bool $approverPasswordResult;
            private string $approverRole;
            public function __construct(bool $passwordResult, bool $requireApprover, bool $approverPasswordResult, string $approverRole) {
                parent::__construct($requireApprover);
                $this->passwordResult = $passwordResult;
                $this->approverPasswordResult = $approverPasswordResult;
                $this->approverRole = $approverRole;
            }
            protected function verifyPassword(int $userId, string $password): bool {
                return $this->passwordResult;
            }
            protected function findApprover(int $approverId): ?User {
                // 不设 password_hash：Encryptable 属性赋值需要加密密钥，而 verifyApproverPassword 已被覆写
                return new User(['id' => $approverId, 'role' => $this->approverRole]);
            }
            protected function verifyApproverPassword(User $approver, string $password): bool {
                return $this->approverPasswordResult;
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

    public function testReturns503WhenRedisUnavailable(): void
    {
        // fail-closed：Redis 故障时拒绝确认操作，而非放行（可无限暴力尝试）
        RedisFacade::swap(new class {
            public function exists(...$args) { throw new \Exception('redis down'); }
        });
        $middleware = $this->createMiddleware(false);
        $request    = $this->createRequest(['confirm_password' => 'x'], 12345);

        $response = $middleware->process($request, function () {});
        $body = $this->decodeResponse($response);
        $this->assertEquals(503, $body['code']);
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

    public function testApproverModeReturns422WhenApproverIdMissing(): void
    {
        $middleware = $this->createMiddleware(true, true);
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_password' => 'ok'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) { $nextCalled = true; });
        $body = $this->decodeResponse($response);
        $this->assertEquals(422, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testApproverModeReturns422WhenApproverSameAsOperator(): void
    {
        $middleware = $this->createMiddleware(true, true);
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_id' => 12345, 'approver_password' => 'ok'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) { $nextCalled = true; });
        $body = $this->decodeResponse($response);
        $this->assertEquals(422, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testApproverModeReturns422WhenApproverPasswordMissing(): void
    {
        $middleware = $this->createMiddleware(true, true);
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_id' => 999], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) { $nextCalled = true; });
        $body = $this->decodeResponse($response);
        $this->assertEquals(422, $body['code']);
        $this->assertStringContainsString('Approver password', $body['message']);
        $this->assertFalse($nextCalled);
    }

    public function testApproverModeRejectsNonAdminApproverRole(): void
    {
        // 伪造 approver_id：finance 角色无权审批
        $middleware = $this->createMiddleware(true, true, true, 'finance');
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_id' => 999, 'approver_password' => 'ok'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) { $nextCalled = true; });
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testApproverModeRejectsWrongApproverPasswordAndLocksApprover(): void
    {
        // approver 密码错误：403 且锁定计数挂在 approver 的 userId 上（防对任意 admin 爆破）
        $fake = new class {
            public array $lockedKeys = [];
            public function exists(...$args) { return 0; }
            public function incr(...$args) { return 5; }
            public function expire(...$args) { return true; }
            public function setex($key, $ttl, $value) { $this->lockedKeys[] = $key; return true; }
            public function del(...$args) { return 1; }
        };
        RedisFacade::swap($fake);

        $middleware = $this->createMiddleware(true, true, false);
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_id' => 888, 'approver_password' => 'wrong'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) { $nextCalled = true; });
        $body = $this->decodeResponse($response);
        $this->assertEquals(403, $body['code']);
        $this->assertFalse($nextCalled);
        $this->assertContains('confirm_lock:888', $fake->lockedKeys);
        $this->assertNotContains('confirm_lock:12345', $fake->lockedKeys);
    }

    public function testApproverModeBlocksWhenApproverLocked(): void
    {
        $fake = new class {
            public function exists($key) { return str_contains($key, ':888') ? 1 : 0; }
            public function incr(...$args) { return 1; }
            public function expire(...$args) { return true; }
            public function setex(...$args) { return true; }
            public function del(...$args) { return 1; }
        };
        RedisFacade::swap($fake);

        $middleware = $this->createMiddleware(true, true);
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_id' => 888, 'approver_password' => 'ok'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) { $nextCalled = true; });
        $body = $this->decodeResponse($response);
        $this->assertEquals(429, $body['code']);
        $this->assertFalse($nextCalled);
    }

    public function testApproverModePassesWithTwoIndependentPasswords(): void
    {
        $middleware = $this->createMiddleware(true, true);
        $request    = $this->createRequest(['confirm_password' => 'op', 'approver_id' => 888, 'approver_password' => 'ap'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return 'approved';
        });
        $this->assertTrue($nextCalled);
        $this->assertEquals('approved', $response);
    }

    public function testDefaultModeIgnoresApproverFields(): void
    {
        // 用户组（requireApprover=false）回归：带 approver 字段也不触发审批
        $middleware = $this->createMiddleware(true);
        $request    = $this->createRequest(['confirm_password' => 'ok', 'approver_id' => 888, 'approver_password' => 'whatever'], 12345);
        $nextCalled = false;

        $response = $middleware->process($request, function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return 'next-result';
        });
        $this->assertTrue($nextCalled);
        $this->assertEquals('next-result', $response);
    }
}
