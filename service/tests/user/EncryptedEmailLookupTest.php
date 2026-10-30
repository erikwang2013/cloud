<?php

namespace Tests\user;

use App\user\model\User;
use App\user\service\AuthService;
use Erikwang2013\Encryptable\Encryption;
use Erikwang2013\WebmanScout\ModelObserver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 加密列查询回归（sqlite，不依赖 MySQL）：
 * email/phone 落库为密文，明文 where 永不命中，AuthService 必须按确定性密文查询。
 * 生产 bootstrap 强制 ECB（非确定性 cipher 直接启动失败），这里用 16 字节密钥复刻。
 */
final class EncryptedEmailLookupTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ENCRYPTION_KEY', 'ENCRYPTION_CIPHER'] as $name) {
            $this->envBackup[$name] = [
                'env'    => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'getenv' => getenv($name),
            ];
        }
        $key = 'base64:' . base64_encode(random_bytes(16));
        foreach (['_ENV', '_SERVER'] as $super) {
            $GLOBALS[$super]['ENCRYPTION_KEY'] = $key;
            $GLOBALS[$super]['ENCRYPTION_CIPHER'] = 'aes-128-ecb';
        }
        putenv('ENCRYPTION_KEY=' . $key);
        putenv('ENCRYPTION_CIPHER=aes-128-ecb');
        Encryption::setFallbackConfig(null);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // 其他测试可能已用旧 dispatcher 启动过 User；清掉 boot 状态并用本测试的
        // dispatcher 重新 boot，否则 HasSnowflakeId 的 creating 监听丢失 → id 为 null。
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container));
        \Illuminate\Database\Eloquent\Model::clearBootedModels();
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('users', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('password_hash');
            $t->string('email_verify_token')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('totp_secret')->nullable();
            $t->boolean('totp_enabled')->default(false);
            $t->string('last_login_ip')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->text('notification_prefs')->nullable();
            $t->string('language')->default('en-US');
            $t->string('currency', 3)->default('USD');
            $t->string('timezone')->default('UTC');
            $t->string('status')->default('active');
            $t->string('role')->default('user');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        // Scout observer 会在 saved 时同步 ES，本地单测无 ES：仅本测试关闭
        ModelObserver::disableSyncingFor(User::class);
    }

    protected function tearDown(): void
    {
        ModelObserver::enableSyncingFor(User::class);
        foreach ($this->envBackup as $name => $values) {
            if ($values['env'] === null) unset($_ENV[$name]); else $_ENV[$name] = $values['env'];
            if ($values['server'] === null) unset($_SERVER[$name]); else $_SERVER[$name] = $values['server'];
            $values['getenv'] === false ? putenv($name) : putenv("{$name}={$values['getenv']}");
        }
        Encryption::setFallbackConfig(null);
        parent::tearDown();
    }

    public function testEncryptedEmailIsQueryableAndSensitiveFieldsStayHidden(): void
    {
        $plainEmail = 'alice@example.com';
        $plainPhone = '+8613800138000';
        $prefs = json_encode(['email' => ['order' => false, 'promo' => true]]);

        $user = User::create([
            'email'              => $plainEmail,
            'phone'              => $plainPhone,
            'password_hash'      => password_hash('Str0ng-pass!', PASSWORD_BCRYPT),
            'email_verify_token' => 'verify-token-123',
            'totp_secret'        => 'JBSWY3DPEHPK3PXP',
            'totp_enabled'       => true,
            'last_login_ip'      => '203.0.113.9',
            'last_login_at'      => '2026-10-30 10:00:00',
            'notification_prefs' => $prefs,
        ]);

        $raw = Capsule::table('users')->where('id', $user->id)->first();
        // 落库为密文，且 ECB 下同明文同密文（确定性查询的前提）
        $this->assertNotSame($plainEmail, $raw->email);
        $this->assertSame(Encryption::php()->encrypt($plainEmail), $raw->email);
        // fillable 字段真实落库（缺失会静默丢弃），且 TOTP/验证 token 同为密文
        $this->assertNotSame('verify-token-123', $raw->email_verify_token);
        $this->assertSame(Encryption::php()->encrypt('verify-token-123'), $raw->email_verify_token);
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw->totp_secret);
        $this->assertSame(Encryption::php()->encrypt('JBSWY3DPEHPK3PXP'), $raw->totp_secret);
        $this->assertSame(1, (int) $raw->totp_enabled);
        $this->assertSame('203.0.113.9', $raw->last_login_ip);
        $this->assertNotNull($raw->last_login_at);
        $this->assertSame($prefs, $raw->notification_prefs);

        // 明文 where 不命中；AuthService 按密文查询命中
        $this->assertNull(User::where('email', $plainEmail)->first());
        // verifyEmail 查询口径：明文 token 不命中，密文命中
        $this->assertNull(User::where('email_verify_token', 'verify-token-123')->first());
        $this->assertSame(
            $user->id,
            User::where('email_verify_token', Encryption::php()->encrypt('verify-token-123'))->first()?->id
        );
        // TOTP 读取路径自动解密
        $this->assertSame('JBSWY3DPEHPK3PXP', User::findOrFail($user->id)->totp_secret);
        $auth = (new \ReflectionClass(AuthService::class))->newInstanceWithoutConstructor();
        $this->assertSame($user->id, $auth->findByEmail($plainEmail)?->id);
        $this->assertSame($user->id, $auth->findByLogin($plainEmail)?->id);
        $this->assertSame($user->id, $auth->findByLogin($plainPhone)?->id);

        // 序列化不得泄露敏感字段；解密 cast 仍可读
        $serialized = User::findOrFail($user->id)->toArray();
        foreach (['password_hash', 'totp_secret', 'email_verify_token', 'last_login_ip', 'email_verified_at'] as $field) {
            $this->assertArrayNotHasKey($field, $serialized);
        }
        $this->assertSame($plainEmail, $serialized['email']);
    }
}
