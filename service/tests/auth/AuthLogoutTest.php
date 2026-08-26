<?php

namespace Tests\auth;

use App\user\controller\AuthController;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class AuthLogoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('refresh_tokens', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('token_hash', 64);
            $t->string('device_fingerprint', 100)->nullable();
            $t->string('client_platform', 30)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('revoked')->default(false);
            $t->timestamps();
        });
    }

    private function seedToken(int $id, string $token, bool $revoked): void
    {
        Capsule::table('refresh_tokens')->insert([
            'id'         => $id,
            'user_id'    => 7,
            'token_hash' => hash('sha256', $token),
            'revoked'    => $revoked ? 1 : 0,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    // 跳过父构造（AuthService/JwtAuth 依赖配置与 Redis，裸测试环境不可用）
    private function controller(): AuthController
    {
        return new class extends AuthController {
            public function __construct()
            {
            }

            public function exposeRevoke(mixed $token): void
            {
                $this->revokeRefreshToken($token);
            }
        };
    }

    public function testLogoutRevokesMatchingRefreshTokenOnly(): void
    {
        $this->seedToken(1, 'tok-a', false);
        $this->seedToken(2, 'tok-b', false);

        $this->controller()->exposeRevoke('tok-a');

        $this->assertSame(1, (int) Capsule::table('refresh_tokens')->where('id', 1)->value('revoked'));
        $this->assertSame(0, (int) Capsule::table('refresh_tokens')->where('id', 2)->value('revoked'));
    }

    public function testLogoutUnknownRefreshTokenIsNoOp(): void
    {
        $this->seedToken(1, 'tok-a', false);

        $this->controller()->exposeRevoke('unknown-token');

        $this->assertSame(0, (int) Capsule::table('refresh_tokens')->where('id', 1)->value('revoked'));
    }

    public function testLogoutWithoutRefreshTokenIsNoOp(): void
    {
        $this->seedToken(1, 'tok-a', false);

        $this->controller()->exposeRevoke(null);
        $this->controller()->exposeRevoke('');
        $this->controller()->exposeRevoke(['not-a-string']);

        $this->assertSame(0, (int) Capsule::table('refresh_tokens')->where('id', 1)->value('revoked'));
    }
}
