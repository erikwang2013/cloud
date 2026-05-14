<?php
namespace App\User\Service;

use App\User\Model\User;
use App\User\Model\UserProfile;
use App\User\Model\UserBalance;
use App\User\Model\RefreshToken;
use Common\Auth\JwtAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

class AuthService
{
    private JwtAuth $jwt;

    public function __construct()
    {
        $this->jwt = new JwtAuth();
    }

    public function register(array $data): array
    {
        $minLength = config('auth.password.min_length', 8);

        if (strlen($data['password']) < $minLength) {
            throw new \InvalidArgumentException('Password too short');
        }

        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            throw new \InvalidArgumentException('Email already registered');
        }

        if (!empty($data['phone']) && User::where('phone', $data['phone'])->exists()) {
            throw new \InvalidArgumentException('Phone already registered');
        }

        $user = User::create([
            'email'         => $data['email'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'password_hash' => Hash::make($data['password'], ['cost' => config('auth.password.cost', 12)]),
            'language'      => $data['language'] ?? config('i18n.default_locale', 'en-US'),
            'currency'      => $data['currency'] ?? 'USD',
            'status'        => 'active',
            'role'          => 'user',
        ]);

        UserProfile::create(['user_id' => $user->id, 'country' => $data['country'] ?? null]);
        UserBalance::create(['user_id' => $user->id, 'currency' => 'USD']);
        UserBalance::create(['user_id' => $user->id, 'currency' => 'CNY']);

        return $this->issueTokens($user->id, 'user');
    }

    public function login(string $login, string $password, string $deviceFingerprint): array
    {
        $user = User::where('email', $login)->orWhere('phone', $login)->first();

        if (!$user || !Hash::check($password, $user->password_hash)) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new \InvalidArgumentException('Account is not active');
        }

        if ($this->isLoginLocked($user->id)) {
            throw new \InvalidArgumentException('Account temporarily locked, try again later');
        }

        return $this->issueTokens($user->id, $user->role, $deviceFingerprint);
    }

    public function refreshToken(string $refreshToken, string $deviceFingerprint): array
    {
        try {
            $payload = $this->jwt->verify($refreshToken);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        if (($payload->type ?? '') !== 'refresh') {
            throw new \InvalidArgumentException('Invalid token type');
        }

        $stored = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
            ->where('revoked', false)
            ->first();

        if (!$stored) {
            throw new \InvalidArgumentException('Token revoked or not found');
        }

        if ($stored->device_fingerprint !== $deviceFingerprint) {
            $this->jwt->revokeAllUserTokens($payload->sub);
            throw new \InvalidArgumentException('Device mismatch, all tokens revoked');
        }

        $stored->update(['revoked' => true]);

        $user = User::findOrFail($payload->sub);
        return $this->issueTokens($user->id, $user->role, $deviceFingerprint);
    }

    private function issueTokens(int $userId, string $role, string $deviceFingerprint = ''): array
    {
        $accessToken  = $this->jwt->issueAccessToken($userId, $role);
        $refreshToken = $this->jwt->issueRefreshToken($userId);

        RefreshToken::create([
            'user_id'            => $userId,
            'token_hash'         => hash('sha256', $refreshToken),
            'device_fingerprint' => $deviceFingerprint,
            'expires_at'         => date('Y-m-d H:i:s', time() + config('auth.jwt.refresh_ttl', 2592000)),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => config('auth.jwt.access_ttl', 7200),
            'token_type'    => 'Bearer',
        ];
    }

    private function isLoginLocked(int $userId): bool
    {
        try {
            return Redis::exists("login_lock:{$userId}");
        } catch (\Exception $e) {
            return false;
        }
    }

    public function recordFailedLogin(string $login): void
    {
        try {
            $key = "login_failed:" . sha1($login);
            $count = Redis::incr($key);
            Redis::expire($key, 900);

            if ($count >= 5) {
                $user = User::where('email', $login)->orWhere('phone', $login)->first();
                if ($user) {
                    Redis::setex("login_lock:{$user->id}", 900, '1');
                }
            }
        } catch (\Exception $e) {
            // Redis unavailable — skip rate limiting
        }
    }
}
