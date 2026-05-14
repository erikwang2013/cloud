<?php
namespace Common\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtAuth
{
    private string $privateKey;
    private string $publicKey;
    private string $algorithm;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $cfg = config('auth.jwt');
        $this->privateKey = $cfg['private_key'];
        $this->publicKey  = $cfg['public_key'];
        $this->algorithm  = $cfg['algorithm'];
        $this->accessTtl  = $cfg['access_ttl'];
        $this->refreshTtl = $cfg['refresh_ttl'];
    }

    public function issueAccessToken(int $userId, string $role): string
    {
        $payload = [
            'iss'  => config('auth.jwt.issuer'),
            'sub'  => $userId,
            'role' => $role,
            'iat'  => time(),
            'exp'  => time() + $this->accessTtl,
            'jti'  => bin2hex(random_bytes(16)),
            'type' => 'access',
        ];
        return JWT::encode($payload, $this->privateKey, $this->algorithm);
    }

    public function issueRefreshToken(int $userId): string
    {
        $payload = [
            'iss'  => config('auth.jwt.issuer'),
            'sub'  => $userId,
            'iat'  => time(),
            'exp'  => time() + $this->refreshTtl,
            'jti'  => bin2hex(random_bytes(16)),
            'type' => 'refresh',
        ];
        return JWT::encode($payload, $this->privateKey, $this->algorithm);
    }

    public function verify(string $token): object
    {
        return JWT::decode($token, new Key($this->publicKey, $this->algorithm));
    }

    public function isRevoked(string $jti): bool
    {
        return Redis::exists("jwt:revoked:{$jti}");
    }

    public function revoke(string $jti): void
    {
        Redis::setex("jwt:revoked:{$jti}", $this->accessTtl, '1');
    }

    public function revokeAllUserTokens(int $userId): void
    {
        \App\User\Model\RefreshToken::where('user_id', $userId)->update(['revoked' => true]);
    }
}
