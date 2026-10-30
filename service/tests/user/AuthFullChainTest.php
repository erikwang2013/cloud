<?php

namespace Tests\user;

use App\user\model\RefreshToken;
use App\user\model\User;
use App\user\model\UserBalance;
use App\user\model\UserProfile;
use App\user\service\AuthService;
use Common\auth\JwtAuth;
use Erikwang2013\Encryptable\Encryption;
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
        // CI 没有 service/.env：safeLoad() 缺失时静默跳过，而不是让整组测试报错
        \Dotenv\Dotenv::createUnsafeMutable($base)->safeLoad();
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

        try {
            \Common\snowflake\SnowflakeService::init();
            \Common\encryption\EncryptionService::init();
            \Common\hashid\HashidService::init();
            \Illuminate\Support\Facades\DB::connection('default')->getPdo();
            self::$dbOk = true;
        } catch (\Throwable $e) {
            // CI 无 .env/MySQL：初始化失败按“环境不可用”跳过，而不是让整组测试报错
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
            \App\notification\model\Notification::where('user_id', $id)->delete();
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
        // email 是 Encryptable 加密列，明文 where 查不到；用 token 里的 user id 定位
        $user = User::findOrFail($this->jwt->verify($tokens['access_token'])['sub']);
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

    public function testLookupByEmailMatchesEncryptedColumn(): void
    {
        [$user] = $this->createUser();

        // 明文 where 永不命中加密列（忘记密码/找回登录修复的根因）
        $this->assertNull(User::where('email', $user->email)->first());

        $found = $this->auth->findByEmail($user->email);
        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);

        $foundByLogin = $this->auth->findByLogin($user->email);
        $this->assertNotNull($foundByLogin);
        $this->assertSame($user->id, $foundByLogin->id);
    }

    public function testSecurityFieldsPersistAndStayHidden(): void
    {
        [$user] = $this->createUser();

        // register 写入的 email_verify_token 必须落库（$fillable 缺失时会被静默丢弃）
        $this->assertNotNull($user->email_verify_token);

        $prefs = ['email' => ['order' => false, 'promo' => true]];
        $user->update([
            'totp_secret'        => 'JBSWY3DPEHPK3PXP',
            'totp_enabled'       => true,
            'last_login_ip'      => '203.0.113.9',
            'last_login_at'      => date('Y-m-d H:i:s'),
            'notification_prefs' => json_encode($prefs),
        ]);

        // fresh model 读回
        $fresh = User::findOrFail($user->id);
        $this->assertSame('JBSWY3DPEHPK3PXP', $fresh->totp_secret);
        $this->assertTrue((bool) $fresh->totp_enabled);
        $this->assertSame('203.0.113.9', $fresh->last_login_ip);
        $this->assertSame($prefs, json_decode($fresh->notification_prefs, true));

        // raw DB 读回（确认真正落库而非内存属性；敏感字段必须是密文）
        $raw = \Illuminate\Support\Facades\DB::connection('default')
            ->table('users')->where('id', $user->id)->first();
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw->totp_secret);
        $this->assertSame(Encryption::php()->encrypt('JBSWY3DPEHPK3PXP'), $raw->totp_secret);
        $this->assertTrue((bool) $raw->totp_enabled);
        $this->assertSame('203.0.113.9', $raw->last_login_ip);
        $this->assertNotNull($raw->last_login_at);
        $this->assertSame($prefs, json_decode($raw->notification_prefs, true));
        $this->assertNotSame($user->email_verify_token, $raw->email_verify_token);
        $this->assertSame(Encryption::php()->encrypt($user->email_verify_token), $raw->email_verify_token);

        // 序列化（GET /user/profile）不得泄露
        $serialized = $fresh->toArray();
        foreach (['totp_secret', 'email_verify_token', 'last_login_ip', 'email_verified_at'] as $field) {
            $this->assertArrayNotHasKey($field, $serialized);
        }
    }

    public function testEmailVerifyTokenStoredEncryptedAndVerifyQueryHits(): void
    {
        [$user] = $this->createUser();
        $token = $user->email_verify_token;
        $this->assertNotNull($token);

        // 明文查不到（verifyEmail 修复的根因），控制器口径按密文查询可命中
        $this->assertNull(User::where('email_verify_token', $token)->first());
        $found = User::where('email_verify_token', Encryption::php()->encrypt($token))->first();
        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);

        // 读回自动解密为明文，URL 拼接使用明文 token
        $this->assertSame($token, $found->email_verify_token);

        // 验证完成后 token 置空，链接不可重放
        $found->update(['email_verified_at' => date('Y-m-d H:i:s'), 'email_verify_token' => null]);
        $fresh = User::findOrFail($user->id);
        $this->assertNull($fresh->email_verify_token);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function testLoginIssuesFreshTokens(): void
    {
        [$user, $tokens] = $this->createUser();

        $loginTokens = $this->auth->login($user->email, 'Str0ng-pass!', 'fp-a', 'test', '', '198.51.100.7');
        $this->assertNotEmpty($loginTokens['access_token']);
        $this->assertNotSame($tokens['access_token'], $loginTokens['access_token']);

        // login 更新登录时间与真实 IP（webman 下 $_SERVER 不可靠，IP 由控制器传入）
        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('198.51.100.7', $user->last_login_ip);
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
