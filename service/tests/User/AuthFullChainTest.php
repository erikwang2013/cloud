<?php

namespace Tests\User;

use App\User\Model\RefreshToken;
use App\User\Model\User;
use App\User\Model\UserBalance;
use App\User\Model\UserProfile;
use App\User\Service\AuthService;
use Common\Auth\JwtAuth;
use PHPUnit\Framework\TestCase;

/**
 * login 全链路真实断言测试：register → login → refresh（旋转）→ logout（黑名单）。
 * 依赖真实 MySQL + Redis（本机 dev 环境），测试数据在 tearDown 中清理。
 */
final class AuthFullChainTest extends TestCase
{
    private static bool $booted = false;
    private static bool $dbOk = false;
    private static string $dbError = '';
    private AuthService $auth;
    private JwtAuth $jwt;
    /** @var int[] */
    private array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        if (self::$booted) {
            return;
        }
        $base = dirname(__DIR__, 2);
        \Dotenv\Dotenv::createUnsafeMutable($base)->load();
        // dotenv 默认只写 $_ENV/$_SERVER，业务代码用 getenv() —— 回灌进程环境
        foreach ($_ENV as $k => $v) {
            putenv("$k=$v");
        }

        \Webman\Config::clear();
        \Webman\Config::load($base . '/config', ['route']);

        $capsule = new \Illuminate\Database\Capsule\Manager;
        $capsule->addConnection(config('database.connections.mysql'), 'default');
        $capsule->addConnection(config('database.connections.audit'), 'audit');
        $dispatcher = new \Illuminate\Events\Dispatcher($capsule->getContainer());
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($capsule->getContainer());
        $capsule->getContainer()->singleton('redis', fn() => \support\Redis::manager());

        \Common\Snowflake\SnowflakeService::init();
        \Common\Encryption\EncryptionService::init();
        \Common\Hashid\HashidService::init();

        try {
            \Illuminate\Support\Facades\DB::connection('default')->getPdo();
            self::$dbOk = true;
        } catch (\Throwable $e) {
            self::$dbError = $e->getMessage();
        }

        self::$booted = true;
    }

    protected function setUp(): void
    {
        if (!self::$dbOk) {
            $this->markTestSkipped('MySQL 不可用（service/.env 的 DB_PASSWORD 未配置）: ' . self::$dbError);
        }
        $this->auth = new AuthService();
        $this->jwt  = new JwtAuth();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUserIds as $id) {
            RefreshToken::where('user_id', $id)->delete();
            UserProfile::where('user_id', $id)->delete();
            UserBalance::where('user_id', $id)->delete();
            \App\Notification\Model\Notification::where('user_id', $id)->delete();
            User::where('id', $id)->forceDelete();
            try {
                \Illuminate\Support\Facades\Redis::del("login_lock:{$id}", "totp_fail:{$id}");
            } catch (\Throwable $e) {
                // Redis 不可用时不阻塞清理
            }
        }
        $this->createdUserIds = [];
    }

    private function createUser(): array
    {
        $email = 'audit-fullchain-' . bin2hex(random_bytes(6)) . '@test.local';
        $tokens = $this->auth->register(['email' => $email, 'password' => 'Str0ng-pass!'], 'test');
        $user = User::where('email', $email)->firstOrFail();
        $this->createdUserIds[] = $user->id;
        return [$user, $tokens];
    }

    public function testRegisterPersistsUserWithHashedPasswordAndBalances(): void
    {
        [$user, $tokens] = $this->createUser();

        $this->assertNotEmpty($tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);
        $this->assertSame('Bearer', $tokens['token_type']);

        $payload = $this->jwt->verify($tokens['access_token']);
        $this->assertSame($user->id, $payload['sub']);
        $this->assertSame('access', $payload['type']);

        // 密码绝不落明文
        $this->assertStringStartsWith('$2y$', $user->password_hash);
        $this->assertNotSame('Str0ng-pass!', $user->password_hash);

        // 注册时同时建 USD + CNY 余额
        $currencies = UserBalance::where('user_id', $user->id)->pluck('currency')->all();
        sort($currencies);
        $this->assertSame(['CNY', 'USD'], $currencies);

        // 恰好一条 refresh token 记录，且存的是哈希而非明文
        $rows = RefreshToken::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNotSame($tokens['refresh_token'], $rows[0]->token_hash);
        $this->assertSame(64, strlen($rows[0]->token_hash));
        $this->assertSame(0, (int) $rows[0]->revoked);
    }

    public function testLoginIssuesFreshTokens(): void
    {
        [$user, $tokens] = $this->createUser();

        $loginTokens = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');
        $this->assertNotEmpty($loginTokens['access_token']);
        $this->assertNotSame($tokens['access_token'], $loginTokens['access_token']);

        // login 更新登录时间
        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        [$user] = $this->createUser();
        $this->expectException(\InvalidArgumentException::class);
        $this->auth->login($user->email, 'wrong-password', 'fp-a', 'test');
    }

    public function testAccountLocksAfterFiveFailedLogins(): void
    {
        [$user] = $this->createUser();
        for ($i = 0; $i < 5; $i++) {
            $this->auth->recordFailedLogin($user->email);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('locked');
        // 正确密码也应被拒（锁定期内）
        $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');
    }

    public function testRefreshRotatesTokenAndRevokesOldOne(): void
    {
        [$user] = $this->createUser();
        $tokens = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');

        $newTokens = $this->auth->refreshToken($tokens['refresh_token'], 'fp-a', 'test');
        $this->assertNotEmpty($newTokens['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $newTokens['refresh_token']);

        // 旧 refresh 已旋转作废
        $oldHash = hash('sha256', $tokens['refresh_token']);
        $this->assertSame(1, (int) RefreshToken::where('token_hash', $oldHash)->firstOrFail()->revoked);

        // 旧 token 再换新 → 拒绝
        try {
            $this->auth->refreshToken($tokens['refresh_token'], 'fp-a', 'test');
            $this->fail('Reusing a rotated refresh token must fail');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
        }

        // 新 token 可继续旋转（连续会话合法）
        $third = $this->auth->refreshToken($newTokens['refresh_token'], 'fp-a', 'test');
        $this->assertNotEmpty($third['access_token']);
    }

    public function testRefreshRejectsAccessToken(): void
    {
        [$user] = $this->createUser();
        $tokens = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('token type');
        $this->auth->refreshToken($tokens['access_token'], 'fp-a', 'test');
    }

    public function testRefreshRejectsGarbageToken(): void
    {
        [$user] = $this->createUser();
        $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');

        $this->expectException(\InvalidArgumentException::class);
        $this->auth->refreshToken('not-a-jwt-token', 'fp-a', 'test');
    }

    public function testDeviceMismatchRevokesAllSessions(): void
    {
        [$user] = $this->createUser();
        $tokens = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');

        try {
            $this->auth->refreshToken($tokens['refresh_token'], 'fp-different', 'test');
            $this->fail('Device mismatch must fail');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
        }

        // 全部 refresh token 已吊销：即使换回正确指纹也失败
        $this->assertSame(
            0,
            RefreshToken::where('user_id', $user->id)->where('revoked', false)->count()
        );
        try {
            $this->auth->refreshToken($tokens['refresh_token'], 'fp-a', 'test');
            $this->fail('Revoked token must fail even with correct fingerprint');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
        }
    }

    public function testLogoutBlacklistsAccessToken(): void
    {
        [$user] = $this->createUser();
        $tokens = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test');

        $this->jwt->blacklist($tokens['access_token']);

        try {
            $this->jwt->verify($tokens['access_token']);
            $this->fail('Blacklisted access token must not verify');
        } catch (\Erikwang2013\Jwt\JWTException $e) {
            $this->assertStringContainsString('blacklist', $e->getMessage());
        }

        // 未黑名单的 token 仍可用（对照）
        $fresh = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-b', 'test');
        $payload = $this->jwt->verify($fresh['access_token']);
        $this->assertSame($user->id, $payload['sub']);
    }
}
