<?php
namespace App\user\service;

use App\user\model\User;
use App\user\model\UserProfile;
use App\user\model\UserBalance;
use App\user\model\RefreshToken;
use Common\auth\JwtAuth;
use Illuminate\Support\Facades\Redis;

class AuthService
{
    private JwtAuth $jwt;

    public function __construct()
    {
        $this->jwt = new JwtAuth();
    }

    public function register(array $data, string $clientPlatform = ''): array
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
            'email'              => $data['email'] ?? null,
            'phone'              => $data['phone'] ?? null,
            'password_hash'      => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => config('auth.password.cost', 12)]),
            'language'           => $data['language'] ?? config('i18n.default_locale', 'en-US'),
            'currency'           => $data['currency'] ?? 'USD',
            'status'             => 'active',
            'role'               => 'user',
            'affiliate_code'     => $data['affiliate_code'] ?? null,
            'email_verify_token' => !empty($data['email']) ? bin2hex(random_bytes(32)) : null,
        ]);

        UserProfile::create(['user_id' => $user->id, 'country' => $data['country'] ?? null]);
        UserBalance::create(['user_id' => $user->id, 'currency' => 'USD']);
        UserBalance::create(['user_id' => $user->id, 'currency' => 'CNY']);

        \Common\security\AuditLogger::record('user_registered', ['user_id' => $user->id]);

        // Send verification email if email provided
        if (!empty($data['email']) && $user->email_verify_token) {
            $verifyUrl = getenv('APP_URL') . '/api/auth/verify-email?token=' . $user->email_verify_token;
            (new \App\notification\service\NotificationDispatcher())->dispatch($user->id, 'email_verify', [
                'verify_url' => $verifyUrl,
            ], ['email']);
        }

        return $this->issueTokens($user->id, 'user', '', $clientPlatform);
    }

    public function login(string $login, string $password, string $deviceFingerprint, string $clientPlatform = '', string $totpCode = ''): array
    {
        $user = User::where('email', $login)->orWhere('phone', $login)->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new \InvalidArgumentException('Account is not active');
        }

        if ($this->isLoginLocked($user->id)) {
            throw new \InvalidArgumentException('Account temporarily locked, try again later');
        }

        if (!empty($user->totp_enabled) && !empty($user->totp_secret)
            && \Common\feature\FeatureFlags::isEnabled('totp_two_factor')) {
            if (empty($totpCode)) {
                throw new \InvalidArgumentException('TOTP code required');
            }
            if (!\Common\auth\TotpService::verify($user->totp_secret, $totpCode)) {
                $this->recordTotpFailure($user->id);
                throw new \InvalidArgumentException('Invalid TOTP code');
            }
        }

        // New IP alert — notify user if login from unrecognized IP
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? '0.0.0.0';
        $lastIp    = $user->last_login_ip;
        if ($lastIp && $lastIp !== $currentIp && !empty($user->email)) {
            try {
                (new \App\notification\service\NotificationDispatcher())->dispatch($user->id, 'new_ip_login', [
                    'ip'   => $currentIp,
                    'time' => date('Y-m-d H:i:s'),
                ], ['email']);
            } catch (\Throwable $e) {
                // Non-critical, don't block login
            }
        }

        $user->update(['last_login_ip' => $currentIp, 'last_login_at' => date('Y-m-d H:i:s')]);

        return $this->issueTokens($user->id, $user->role, $deviceFingerprint, $clientPlatform);
    }

    public function refreshToken(string $refreshToken, string $deviceFingerprint, string $clientPlatform = ''): array
    {
        try {
            $payload = $this->jwt->verify($refreshToken);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        if (($payload['type'] ?? '') !== 'refresh') {
            throw new \InvalidArgumentException('Invalid token type');
        }

        $stored = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
            ->where('revoked', false)
            ->first();

        if (!$stored) {
            throw new \InvalidArgumentException('Token revoked or not found');
        }

        if ($stored->device_fingerprint !== $deviceFingerprint) {
            $this->jwt->revokeAllUserTokens($payload['sub']);
            throw new \InvalidArgumentException('Device mismatch, all tokens revoked');
        }

        $stored->update(['revoked' => true]);

        $user = User::findOrFail($payload['sub']);
        return $this->issueTokens($user->id, $user->role, $deviceFingerprint, $clientPlatform);
    }

    public function issueTokens(int $userId, string $role, string $deviceFingerprint = '', string $clientPlatform = ''): array
    {
        $accessToken  = $this->jwt->issueAccessToken($userId, $role);
        $refreshToken = $this->jwt->issueRefreshToken($userId);

        RefreshToken::create([
            'user_id'            => $userId,
            'token_hash'         => hash('sha256', $refreshToken),
            'device_fingerprint' => $deviceFingerprint,
            'client_platform'    => $clientPlatform,
            'expires_at'         => date('Y-m-d H:i:s', time() + config('plugin.erikwang2013.jwt.jwt.refresh_expire', 2592000)),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => config('plugin.erikwang2013.jwt.jwt.default_expire', 900),
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

    private function recordTotpFailure(int $userId): void
    {
        try {
            $key = "totp_fail:{$userId}";
            $count = Redis::incr($key);
            Redis::expire($key, 900);

            if ($count >= 5) {
                Redis::setex("login_lock:{$userId}", 900, '1');
            }
        } catch (\Exception $e) {
            // Redis unavailable — skip rate limiting
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
