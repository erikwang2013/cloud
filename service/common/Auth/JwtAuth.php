<?php
namespace Common\Auth;

use Erikwang2013\Jwt\JWTFactory;
use Erikwang2013\Jwt\JWT;
use App\User\Model\RefreshToken;

class JwtAuth
{
    private JWT $jwt;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $cfg = config('plugin.erikwang2013.jwt.jwt');
        // fail-fast：密钥缺失时直接拒绝启动，避免退化为空密钥 HS256（可伪造 token）
        if (empty($cfg['secret_key'])) {
            throw new \RuntimeException('JWT_SECRET_KEY is not configured');
        }
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
