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
    }

    public function testSensitiveFieldsUseEncryptableCast(): void
    {
        $casts = (new User())->getCasts();
        foreach (['email', 'phone', 'password_hash'] as $field) {
            $this->assertSame(\Erikwang2013\Encryptable\Encryptable::class, $casts[$field]);
        }
    }

    public function testEncryptableRoundTripEncryptsAtRest(): void
    {
        $user = new User();
        $user->email = 'alice@example.com';
        $user->phone = '+8613800138000';

        $raw = $user->getAttributes();
        $this->assertNotSame('alice@example.com', $raw['email']);
        $this->assertNotSame('+8613800138000', $raw['phone']);
        $this->assertIsString($raw['email']);
        $this->assertNotEmpty($raw['email']);

        $this->assertSame('alice@example.com', $user->email);
        $this->assertSame('+8613800138000', $user->phone);
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
