<?php

namespace Tests\user;

use App\user\model\User;
use PHPUnit\Framework\TestCase;

final class UserModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // AuthFullChainTest 会把 service/.env 注入 $_ENV/$_SERVER（cipher=aes-128-ecb、
        // 24 字符非 base64 密钥），且 Encryptable 包进程级静态缓存解析结果 ——
        // 本测试显式钉死 32 字节密钥 + aes-256-gcm 并重置包缓存，保证隔离。
        $key = base64_decode('dW5pdC10ZXN0LW1hc3Rlci1rZXktMzJieXRlcy1hYmM=');
        foreach (['_ENV', '_SERVER'] as $super) {
            $GLOBALS[$super]['ENCRYPTION_KEY'] = $key;
            $GLOBALS[$super]['ENCRYPTION_CIPHER'] = 'aes-256-gcm';
        }
        putenv('ENCRYPTION_KEY=' . $key);
        putenv('ENCRYPTION_CIPHER=aes-256-gcm');
        \Erikwang2013\Encryptable\Encryption::setFallbackConfig(null);
    }

    public function testPasswordHashNeverSerialized(): void
    {
        $hidden = (new User())->getHidden();
        $this->assertContains('password_hash', $hidden);
        $this->assertContains('deleted_at', $hidden);
        // GET /user/profile 序列化整个 User，敏感事务字段不得外泄
        foreach (['totp_secret', 'email_verify_token', 'last_login_ip', 'email_verified_at'] as $field) {
            $this->assertContains($field, $hidden, "missing hidden: {$field}");
        }
    }

    public function testTransactionalFieldsAreMassAssignable(): void
    {
        $fillable = (new User())->getFillable();
        // 注册/登录/TOTP/邮箱验证/通知偏好写入的字段，缺失会被 Eloquent 静默丢弃
        foreach (['email_verify_token', 'email_verified_at', 'totp_secret', 'totp_enabled',
                     'last_login_ip', 'last_login_at', 'notification_prefs'] as $field) {
            $this->assertContains($field, $fillable, "missing fillable: {$field}");
        }
    }

    public function testSensitiveFieldsUseEncryptableCast(): void
    {
        $casts = (new User())->getCasts();
        foreach (['email', 'phone', 'password_hash', 'totp_secret', 'email_verify_token'] as $field) {
            $this->assertSame(\Erikwang2013\Encryptable\Encryptable::class, $casts[$field]);
        }
    }

    public function testEncryptableRoundTripEncryptsAtRest(): void
    {
        $user = new User();
        $user->email = 'alice@example.com';
        $user->phone = '+8613800138000';
        $user->totp_secret = 'JBSWY3DPEHPK3PXP';
        $user->email_verify_token = str_repeat('ab', 32);

        $raw = $user->getAttributes();
        $this->assertNotSame('alice@example.com', $raw['email']);
        $this->assertNotSame('+8613800138000', $raw['phone']);
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw['totp_secret']);
        $this->assertNotSame(str_repeat('ab', 32), $raw['email_verify_token']);
        $this->assertIsString($raw['email']);
        $this->assertNotEmpty($raw['email']);

        $this->assertSame('alice@example.com', $user->email);
        $this->assertSame('+8613800138000', $user->phone);
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->totp_secret);
        $this->assertSame(str_repeat('ab', 32), $user->email_verify_token);
    }

    public function testUserUsesSoftDeletes(): void
    {
        $traits = class_uses_recursive(User::class);
        $this->assertContains(\Illuminate\Database\Eloquent\SoftDeletes::class, $traits);
        $this->assertSame('deleted_at', (new User())->getDeletedAtColumn());
    }

    public function testUserUsesSnowflakeId(): void
    {
        $user = new User();
        $this->assertFalse($user->getIncrementing());
        $this->assertSame('int', $user->getKeyType());
    }

    public function testRelations(): void
    {
        $user = new User();
        foreach (['profile', 'kyc', 'balances', 'addresses'] as $rel) {
            $this->assertTrue(method_exists($user, $rel), "missing relation: {$rel}");
        }
    }
}
