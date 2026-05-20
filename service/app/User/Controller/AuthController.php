<?php
namespace App\User\Controller;

use App\User\Service\AuthService;
use Common\Auth\TotpService;
use Common\Captcha\CaptchaService;
use Common\Helper\Response;
use Common\Helper\Validator;
use Common\I18n\I18n;
use Common\Security\AuditLogger;
use App\User\Model\User;

class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function register($request)
    {
        $data = $request->all();
        if (empty($data['password']) || (empty($data['email']) && empty($data['phone']))) {
            return json(Response::error(422, 'Email or phone required, and password required'));
        }

        if (!$this->verifyCaptcha($request)) {
            return json(Response::error(422, 'Captcha verification failed'));
        }

        if (!empty($data['email']) && !Validator::email($data['email'])) {
            return json(Response::error(422, 'Invalid email format'));
        }
        if (!Validator::minLength($data['password'], 8)) {
            return json(Response::error(422, 'Password must be at least 8 characters'));
        }

        try {
            $tokens = $this->auth->register($data, $this->clientPlatform($request));
            AuditLogger::record('user_registered', ['user_id' => null], $request);
            return json(Response::success($tokens, I18n::trans('auth.register_success')));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(422, $e->getMessage()));
        }
    }

    public function login($request)
    {
        $login    = $request->input('login');
        $password = $request->input('password');
        $deviceFp = $this->deviceFingerprint($request);

        if (empty($login) || empty($password)) {
            return json(Response::error(422, 'Login and password required'));
        }

        if (!$this->verifyCaptcha($request)) {
            return json(Response::error(422, 'Captcha verification failed'));
        }

        try {
            $tokens = $this->auth->login($login, $password, $deviceFp, $this->clientPlatform($request));
            $payload = (new \Common\Auth\JwtAuth())->verify($tokens['access_token']);
            AuditLogger::record('user_login', ['user_id' => $payload['sub'] ?? null], $request);
            return json(Response::success($tokens, I18n::trans('auth.login_success')));
        } catch (\InvalidArgumentException $e) {
            $this->auth->recordFailedLogin($login);
            AuditLogger::record('login_failed', ['input' => ['login_hash' => sha1($login)]], $request);
            return json(Response::error(401, $e->getMessage()));
        }
    }

    public function refresh($request)
    {
        $refreshToken = $request->input('refresh_token');
        $deviceFp     = $this->deviceFingerprint($request);

        try {
            $tokens = $this->auth->refreshToken($refreshToken, $deviceFp, $this->clientPlatform($request));
            return json(Response::success($tokens));
        } catch (\InvalidArgumentException $e) {
            return json(Response::error(401, $e->getMessage()));
        }
    }

    private function verifyCaptcha($request): bool
    {
        $key    = $request->input('captcha_key', '');
        $points = $request->input('captcha_points', []);

        if (empty($key) || !is_array($points) || empty($points)) {
            return false;
        }

        return CaptchaService::verify($key, $points);
    }

    public function forgotPassword($request)
    {
        $email = $request->input('email');
        if (empty($email)) {
            return json(Response::error(422, 'Email required'));
        }

        $user = \App\User\Model\User::where('email', $email)->first();
        if (!$user) {
            // Return success even if not found to prevent enumeration
            return json(Response::success(null, 'If the email exists, a reset code has been sent'));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        \Illuminate\Support\Facades\Redis::setex("password_reset:{$email}", 600, $code);

        // Dispatch email asynchronously via NotificationDispatcher
        \Common\Notification\NotificationDispatcher::send($user, 'password_reset', [
            'code' => $code,
        ], ['email']);

        return json(Response::success(null, 'If the email exists, a reset code has been sent'));
    }

    public function resetPassword($request)
    {
        $email    = $request->input('email');
        $code     = $request->input('code');
        $password = $request->input('password');

        if (empty($email) || empty($code) || empty($password)) {
            return json(Response::error(422, 'Email, code, and password are required'));
        }
        if (!\Common\Helper\Validator::minLength($password, 8)) {
            return json(Response::error(422, 'Password must be at least 8 characters'));
        }

        $stored = \Illuminate\Support\Facades\Redis::get("password_reset:{$email}");
        if (!$stored || $stored !== $code) {
            return json(Response::error(422, 'Invalid or expired reset code'));
        }

        $user = \App\User\Model\User::where('email', $email)->firstOrFail();
        $user->update(['password_hash' => \Illuminate\Support\Facades\Hash::make($password, ['cost' => 12])]);
        \Illuminate\Support\Facades\Redis::del("password_reset:{$email}");

        // Revoke all existing refresh tokens
        \App\User\Model\RefreshToken::where('user_id', $user->id)->update(['revoked' => true]);

        AuditLogger::record('password_reset', ['user_id' => $user->id], $request);
        return json(Response::success(null, 'Password has been reset successfully'));
    }

    public function totpSetup($request)
    {
        $user = User::findOrFail($request->userId);
        $secret = TotpService::generateSecret();

        // Store pending secret in Redis (not persisted until verified)
        \Illuminate\Support\Facades\Redis::setex("totp_pending:{$user->id}", 600, $secret);

        return json(Response::success([
            'secret'    => $secret,
            'qr_url'    => TotpService::getQrUrl($user->email ?? (string) $user->id, $secret),
            'manual'    => 'Use this secret in your authenticator app: ' . $secret,
        ]));
    }

    public function totpVerify($request)
    {
        $user = User::findOrFail($request->userId);
        $code = $request->input('code');

        if (empty($code)) {
            return json(Response::error(422, 'TOTP code required'));
        }

        // Check if verifying a pending setup
        $pendingSecret = \Illuminate\Support\Facades\Redis::get("totp_pending:{$user->id}");
        if ($pendingSecret && TotpService::verify($pendingSecret, $code)) {
            \Illuminate\Support\Facades\Redis::del("totp_pending:{$user->id}");
            $user->update(['totp_secret' => $pendingSecret, 'totp_enabled' => true]);
            AuditLogger::record('totp_enabled', ['user_id' => $user->id], $request);
            return json(Response::success(null, 'Two-factor authentication has been enabled'));
        }

        // Check against existing TOTP
        if ($user->totp_enabled && $user->totp_secret) {
            if (TotpService::verify($user->totp_secret, $code)) {
                return json(Response::success(['verified' => true]));
            }
            return json(Response::error(422, 'Invalid TOTP code'));
        }

        return json(Response::error(400, 'No pending TOTP setup found. Call totp/setup first.'));
    }

    public function totpDisable($request)
    {
        $user     = User::findOrFail($request->userId);
        $password = $request->input('password');

        if (empty($password) || !\Illuminate\Support\Facades\Hash::check($password, $user->password_hash)) {
            return json(Response::error(403, 'Password verification required to disable 2FA'));
        }

        $user->update(['totp_secret' => null, 'totp_enabled' => false]);
        AuditLogger::record('totp_disabled', ['user_id' => $user->id], $request);
        return json(Response::success(null, 'Two-factor authentication has been disabled'));
    }

    public function googleOauthRedirect($request)
    {
        $clientId     = getenv('GOOGLE_OAUTH_CLIENT_ID');
        $redirectUri  = getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: url('/api/auth/google/callback');
        $state        = bin2hex(random_bytes(16));

        \Illuminate\Support\Facades\Redis::setex("oauth_state:{$state}", 300, $request->input('redirect', '/'));

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
        ]);

        return json(Response::success(['url' => $url]));
    }

    public function googleOauthCallback($request)
    {
        $code  = $request->input('code');
        $state = $request->input('state');
        $clientId     = getenv('GOOGLE_OAUTH_CLIENT_ID');
        $clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET');
        $redirectUri  = getenv('GOOGLE_OAUTH_REDIRECT_URI') ?: url('/api/auth/google/callback');

        if (empty($code)) {
            return json(Response::error(422, 'Authorization code required'));
        }

        $storedRedirect = \Illuminate\Support\Facades\Redis::get("oauth_state:{$state}");
        if (!$storedRedirect) {
            return json(Response::error(422, 'Invalid state'));
        }
        \Illuminate\Support\Facades\Redis::del("oauth_state:{$state}");

        // Exchange code for token
        $http = new \GuzzleHttp\Client();
        $tokenResponse = $http->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ],
        ]);
        $tokenData = json_decode((string) $tokenResponse->getBody(), true);

        if (empty($tokenData['access_token'])) {
            return json(Response::error(401, 'Failed to obtain access token'));
        }

        // Get user info
        $userResponse = $http->get('https://openidconnect.googleapis.com/v1/userinfo', [
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['access_token']],
        ]);
        $googleUser = json_decode((string) $userResponse->getBody(), true);

        // Find or create user
        $user = User::where('email', $googleUser['email'])->first();
        if (!$user) {
            try {
                $tokens = $this->auth->register([
                    'email'    => $googleUser['email'],
                    'password' => bin2hex(random_bytes(16)),
                    'language' => $request->header('Accept-Language', 'en-US'),
                    'currency' => 'USD',
                ], $this->clientPlatform($request));
                // Update profile with Google data
                $user = User::where('email', $googleUser['email'])->first();
                \App\User\Model\UserProfile::where('user_id', $user->id)->update([
                    'avatar'   => $googleUser['picture'] ?? null,
                    'nickname' => $googleUser['name'] ?? null,
                ]);
                AuditLogger::record('user_oauth_register', ['provider' => 'google', 'email' => $googleUser['email']], $request);
                return json(Response::success($tokens));
            } catch (\Exception $e) {
                return json(Response::error(500, 'Registration failed'));
            }
        }

        $deviceFp = $this->deviceFingerprint($request);
        $tokens = $this->auth->issueTokens($user->id, $user->role, $deviceFp, $this->clientPlatform($request));
        AuditLogger::record('user_oauth_login', ['provider' => 'google', 'user_id' => $user->id], $request);
        return json(Response::success($tokens));
    }

    public function appleOauthRedirect($request)
    {
        $clientId    = getenv('APPLE_OAUTH_CLIENT_ID');
        $redirectUri = getenv('APPLE_OAUTH_REDIRECT_URI') ?: url('/api/auth/apple/callback');
        $state       = bin2hex(random_bytes(16));

        \Illuminate\Support\Facades\Redis::setex("oauth_state:{$state}", 300, $request->input('redirect', '/'));

        $url = 'https://appleid.apple.com/auth/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'name email',
            'state'         => $state,
            'response_mode' => 'form_post',
        ]);

        return json(Response::success(['url' => $url]));
    }

    public function appleOauthCallback($request)
    {
        $code  = $request->input('code');
        $state = $request->input('state');

        if (empty($code)) return json(Response::error(422, 'Authorization code required'));

        $stored = \Illuminate\Support\Facades\Redis::get("oauth_state:{$state}");
        if (!$stored) return json(Response::error(422, 'Invalid state'));
        \Illuminate\Support\Facades\Redis::del("oauth_state:{$state}");

        // Exchange code for tokens via Apple's token endpoint
        $clientSecret = $this->generateAppleClientSecret();
        $http   = new \GuzzleHttp\Client();
        $resp   = $http->post('https://appleid.apple.com/auth/token', [
            'form_params' => [
                'client_id'     => getenv('APPLE_OAUTH_CLIENT_ID'),
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => getenv('APPLE_OAUTH_REDIRECT_URI') ?: url('/api/auth/apple/callback'),
            ],
        ]);
        $tokenData = json_decode((string) $resp->getBody(), true);

        if (empty($tokenData['id_token'])) return json(Response::error(401, 'Failed to obtain ID token'));

        // Decode the id_token (JWT) to get user info
        $jwtParts = explode('.', $tokenData['id_token']);
        $claims   = json_decode(base64_decode($jwtParts[1]), true);

        $email = $claims['email'] ?? null;
        if (!$email) {
            return json(Response::error(400, 'Email not provided by Apple. User may need to re-authorize.'));
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            try {
                $tokens = $this->auth->register([
                    'email'    => $email,
                    'password' => bin2hex(random_bytes(16)),
                    'language' => $request->header('Accept-Language', 'en-US'),
                    'currency' => 'USD',
                ], $this->clientPlatform($request));
                AuditLogger::record('user_oauth_register', ['provider' => 'apple', 'email' => $email], $request);
                return json(Response::success($tokens));
            } catch (\Exception $e) {
                return json(Response::error(500, 'Registration failed'));
            }
        }

        $deviceFp = $this->deviceFingerprint($request);
        $tokens   = $this->auth->issueTokens($user->id, $user->role, $deviceFp, $this->clientPlatform($request));
        AuditLogger::record('user_oauth_login', ['provider' => 'apple', 'user_id' => $user->id], $request);
        return json(Response::success($tokens));
    }

    private function generateAppleClientSecret(): string
    {
        $teamId   = getenv('APPLE_TEAM_ID');
        $clientId = getenv('APPLE_OAUTH_CLIENT_ID');
        $keyId    = getenv('APPLE_KEY_ID');
        $keyPath  = getenv('APPLE_PRIVATE_KEY_PATH');

        if (!$keyPath || !file_exists($keyPath)) {
            throw new \RuntimeException('Apple private key not found');
        }

        $privateKey = file_get_contents($keyPath);
        $payload = [
            'iss' => $teamId,
            'iat' => time(),
            'exp' => time() + 86400 * 180,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ];

        return \Firebase\JWT\JWT::encode($payload, $privateKey, 'ES256', $keyId);
    }

    public function sendSmsVerify($request)
    {
        $phone = $request->input('phone');
        if (empty($phone)) {
            return json(Response::error(422, 'Phone number required'));
        }

        $ip    = $request->getRealIp();
        $cooldownKey = "sms_cooldown:{$phone}";
        $ipLimitKey  = "sms_ip_limit:{$ip}";

        // Rate limit: 60s cooldown per phone number
        if (\Illuminate\Support\Facades\Redis::exists($cooldownKey)) {
            return json(Response::error(429, 'Please wait before requesting another code'));
        }

        // Rate limit: max 5 SMS per IP per hour
        $ipCount = (int) \Illuminate\Support\Facades\Redis::get($ipLimitKey);
        if ($ipCount >= 5) {
            return json(Response::error(429, 'Too many SMS requests'));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store code in Redis with 10min TTL
        \Illuminate\Support\Facades\Redis::setex("sms_verify:{$phone}", 600, $code);
        \Illuminate\Support\Facades\Redis::setex($cooldownKey, 60, '1');
        \Illuminate\Support\Facades\Redis::incr($ipLimitKey);
        \Illuminate\Support\Facades\Redis::expire($ipLimitKey, 3600);

        // Send via Twilio
        try {
            $twilio = new \Twilio\Rest\Client(
                getenv('TWILIO_ACCOUNT_SID'),
                getenv('TWILIO_AUTH_TOKEN')
            );
            $twilio->messages->create($phone, [
                'from' => getenv('TWILIO_PHONE_NUMBER'),
                'body' => "Your CloudPlatform verification code: {$code}",
            ]);
        } catch (\Exception $e) {
            if (getenv('APP_DEBUG') === 'true') {
                \Illuminate\Support\Facades\Redis::setex("sms_verify_debug:{$phone}", 600, $code);
            }
        }

        return json(Response::success(null, 'Verification code sent'));
    }

    // --- Email verification ---

    public function verifyEmail($request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            return json(Response::error(422, 'Verification token required'));
        }

        $user = User::where('email_verify_token', $token)->first();
        if (!$user) {
            return json(Response::error(422, 'Invalid or expired verification token'));
        }

        $user->update(['email_verified_at' => now(), 'email_verify_token' => null]);
        AuditLogger::record('email_verified', ['user_id' => $user->id], $request);
        return json(Response::success(null, 'Email verified successfully'));
    }

    public function resendVerifyEmail($request)
    {
        $user = User::findOrFail($request->userId);
        if ($user->email_verified_at) {
            return json(Response::error(422, 'Email already verified'));
        }

        $user->update(['email_verify_token' => bin2hex(random_bytes(32))]);
        $verifyUrl = getenv('APP_URL') . '/api/auth/verify-email?token=' . $user->email_verify_token;
        \Common\Notification\NotificationDispatcher::send($user, 'email_verify', [
            'verify_url' => $verifyUrl,
        ], ['email']);

        return json(Response::success(null, 'Verification email sent'));
    }

    // --- TOTP recovery codes ---

    public function totpRecoveryCodes($request)
    {
        $user = User::findOrFail($request->userId);
        if (!$user->totp_enabled) {
            return json(Response::error(400, 'TOTP is not enabled'));
        }

        // Generate 8 single-use recovery codes
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(5)); // 10-char hex
        }

        \Illuminate\Support\Facades\Redis::setex("totp_recovery:{$user->id}", 86400 * 365, json_encode($codes));
        return json(Response::success(['recovery_codes' => $codes], 'Store these codes securely. Each code can only be used once.'));
    }

    public function loginWithRecoveryCode($request)
    {
        $login    = $request->input('login');
        $password = $request->input('password');
        $code     = $request->input('recovery_code');

        if (empty($login) || empty($password) || empty($code)) {
            return json(Response::error(422, 'Login, password, and recovery code required'));
        }

        $user = User::where('email', $login)->orWhere('phone', $login)->first();
        if (!$user || !\Illuminate\Support\Facades\Hash::check($password, $user->password_hash)) {
            return json(Response::error(401, 'Invalid credentials'));
        }

        $stored = \Illuminate\Support\Facades\Redis::get("totp_recovery:{$user->id}");
        if (!$stored) {
            return json(Response::error(400, 'No recovery codes available. TOTP may not be enabled.'));
        }

        $codes = json_decode($stored, true);
        $index = array_search($code, $codes);
        if ($index === false) {
            return json(Response::error(422, 'Invalid recovery code'));
        }

        // Remove used code
        array_splice($codes, $index, 1);
        \Illuminate\Support\Facades\Redis::setex("totp_recovery:{$user->id}", 86400 * 365, json_encode($codes));

        $deviceFp = $this->deviceFingerprint($request);
        $tokens   = $this->auth->issueTokens($user->id, $user->role, $deviceFp, $this->clientPlatform($request));
        AuditLogger::record('user_login_recovery_code', ['user_id' => $user->id], $request);
        return json(Response::success($tokens));
    }

    // --- Session management ---

    public function sessions($request)
    {
        $sessions = \App\User\Model\RefreshToken::where('user_id', $request->userId)
            ->where('revoked', false)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($t) => [
                'id'              => $t->id,
                'fingerprint'     => substr($t->device_fingerprint, 0, 16),
                'client_platform' => $t->client_platform ?? 'unknown',
                'created_at'      => $t->created_at,
                'expires_at'      => $t->expires_at,
            ]);

        return json(Response::success($sessions));
    }

    public function revokeSession($request, int $id)
    {
        \App\User\Model\RefreshToken::where('id', $id)
            ->where('user_id', $request->userId)
            ->update(['revoked' => true]);

        return json(Response::success(null, 'Session revoked'));
    }

    // --- Account deletion ---

    public function deleteAccount($request)
    {
        $user     = User::findOrFail($request->userId);
        $password = $request->input('password');

        if (empty($password) || !\Illuminate\Support\Facades\Hash::check($password, $user->password_hash)) {
            return json(Response::error(403, 'Password verification required'));
        }

        // Revoke all tokens
        \App\User\Model\RefreshToken::where('user_id', $user->id)->update(['revoked' => true]);

        // Soft delete user
        $user->update(['status' => 'deleted']);
        $user->delete();

        AuditLogger::record('account_deleted', ['user_id' => $user->id], $request);
        return json(Response::success(null, 'Account deleted. Data will be retained per our retention policy.'));
    }

    private function deviceFingerprint($request): string
    {
        $ua     = $request->header('User-Agent', '');
        $ip     = $request->getRealIp();
        $ipCidr = substr($ip, 0, (int) strrpos($ip, '.'));
        return hash('sha256', $ua . $ipCidr);
    }

    private function clientPlatform($request): string
    {
        return $request->properties['client_platform'] ?? 'unknown';
    }
}
