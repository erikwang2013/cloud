<?php

// JwtAuth reads config('plugin.erikwang2013.jwt.jwt') via the global
// config() helper, which webman provides but PHPUnit does not. Shadow it
// in the class's namespace so the real code path runs with a test secret.
// Storage is file-based so no Redis is touched.
namespace Common\auth {

    function config(string $key = null, $default = null)
    {
        static $cfg = [
            'plugin.erikwang2013.jwt.jwt' => [
                'secret_key'     => 'unit-test-secret-key-0123456789abcdef',
                'algorithm'      => 'HS256',
                'issuer'         => 'cloud-platform-test',
                'audience'       => '',
                'leeway'         => 0,
                'default_expire' => 900,
                'refresh_expire' => 2592000,
                'storage'        => ['type' => 'file', 'prefix' => 'jwt_blacklist:', 'database' => 0],
                'advanced'       => ['retry_attempts' => 1, 'retry_delay' => 100, 'auto_cleanup' => false, 'cleanup_interval' => 3600],
            ],
        ];
        return $key === null ? $cfg : ($cfg[$key] ?? $default);
    }
}

namespace Tests\auth {

    use Common\auth\JwtAuth;
    use PHPUnit\Framework\TestCase;

    final class JwtAuthTest extends TestCase
    {
        public function testIssueAccessTokenRoundTrip(): void
        {
            $jwt = new JwtAuth();
            $token = $jwt->issueAccessToken(42, 'admin');
            $this->assertIsString($token);
            $this->assertNotEmpty($token);

            $payload = $jwt->verify($token);
            $this->assertSame(42, $payload['sub']);
            $this->assertSame('admin', $payload['role']);
            $this->assertSame('access', $payload['type']);
        }

        public function testIssueRefreshTokenRoundTrip(): void
        {
            $jwt = new JwtAuth();
            $token = $jwt->issueRefreshToken(7);
            $payload = $jwt->verify($token);
            $this->assertSame(7, $payload['sub']);
            $this->assertSame('refresh', $payload['type']);
            $this->assertArrayNotHasKey('role', $payload);
        }

        public function testAccessAndRefreshTokensDiffer(): void
        {
            $jwt = new JwtAuth();
            $this->assertNotSame($jwt->issueAccessToken(1, 'user'), $jwt->issueRefreshToken(1));
        }

        public function testVerifyRejectsTamperedToken(): void
        {
            $jwt = new JwtAuth();
            $token = $jwt->issueAccessToken(42, 'admin');
            $tampered = substr($token, 0, -2) . 'xx';

            $this->expectException(\Erikwang2013\Jwt\JWTException::class);
            $this->expectExceptionCode(\Erikwang2013\Jwt\JWTException::TOKEN_INVALID);
            $jwt->verify($tampered);
        }

        public function testBlacklistedTokenIsRejected(): void
        {
            $jwt = new JwtAuth();
            $token = $jwt->issueAccessToken(42, 'admin');
            $jwt->blacklist($token);

            $this->expectException(\Erikwang2013\Jwt\JWTException::class);
            $this->expectExceptionCode(\Erikwang2013\Jwt\JWTException::TOKEN_BLACKLISTED);
            $jwt->verify($token);
        }
    }
}
