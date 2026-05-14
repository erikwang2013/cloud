<?php
namespace Common\Auth;

use ErikJwt\JWTFactory;
use ErikJwt\JWT;
use App\User\Model\RefreshToken;

class JwtAuth
{
    private JWT $jwt;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $cfg = config('plugin.erikwang2013.jwt.jwt');
        $this->accessTtl  = (int)($cfg['default_expire'] ?? 900);
        $this->refreshTtl = (int)($cfg['refresh_expire'] ?? 2592000);

        $this->jwt = JWTFactory::createFromConfig($cfg, null, [
            'redis' => fn() => \support\Redis::connection(),
        ]);
    }

    public function issueAccessToken(int $userId, string $role): string
    {
        return $this->jwt->encode([
            'sub'  => $userId,
            'role' => $role,
            'type' => 'access',
            'exp'  => time() + $this->accessTtl,
        ]);
    }

    public function issueRefreshToken(int $userId): string
    {
        return $this->jwt->encode([
            'sub'  => $userId,
            'type' => 'refresh',
            'exp'  => time() + $this->refreshTtl,
        ]);
    }

    public function verify(string $token): array
    {
        return $this->jwt->decode($token);
    }

    public function blacklist(string $token): void
    {
        $this->jwt->blacklist($token);
    }

    public function revokeAllUserTokens(int $userId): void
    {
        RefreshToken::where('user_id', $userId)->update(['revoked' => true]);
    }
}
