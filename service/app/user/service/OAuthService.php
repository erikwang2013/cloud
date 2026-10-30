<?php
namespace App\user\service;

use App\user\model\User;
use App\user\model\UserProfile;
use Common\security\AuditLogger;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Redis;

class OAuthService
{
    private const PROVIDERS = [
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token'     => 'https://oauth2.googleapis.com/token',
            'userinfo'  => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scopes'    => 'openid email profile',
        ],
        'apple' => [
            'authorize'     => 'https://appleid.apple.com/auth/authorize',
            'token'         => 'https://appleid.apple.com/auth/token',
            'scopes'        => 'name email',
            'response_mode' => 'form_post',
        ],
        'facebook' => [
            'authorize' => 'https://www.facebook.com/v20.0/dialog/oauth',
            'token'     => 'https://graph.facebook.com/v20.0/oauth/access_token',
            'userinfo'  => 'https://graph.facebook.com/me',
            'scopes'    => 'email public_profile',
        ],
        'x' => [
            'authorize' => 'https://twitter.com/i/oauth2/authorize',
            'token'     => 'https://api.twitter.com/2/oauth2/token',
            'userinfo'  => 'https://api.twitter.com/2/users/me',
            'scopes'    => 'tweet.read users.read offline.access',
            'pkce'      => true,
        ],
        'microsoft' => [
            'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'userinfo'  => 'https://graph.microsoft.com/v1.0/me',
            'scopes'    => 'openid email profile User.Read',
        ],
        'linkedin' => [
            'authorize' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token'     => 'https://www.linkedin.com/oauth/v2/accessToken',
            'userinfo'  => 'https://api.linkedin.com/v2/userinfo',
            'scopes'    => 'openid profile email',
        ],
        'github' => [
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token'     => 'https://github.com/login/oauth/access_token',
            'userinfo'  => 'https://api.github.com/user',
            'scopes'    => 'user:email read:user',
        ],
    ];

    private AuthService $auth;
    private Client $http;

    public function __construct(?AuthService $auth = null, ?Client $http = null)
    {
        $this->auth = $auth ?? new AuthService();
        $this->http = $http ?? new Client(['http_errors' => false, 'timeout' => 10]);
    }

    public function authorizeUrl(string $provider): string
    {
        $conf = $this->config($provider);

        $state   = bin2hex(random_bytes(16));
        $payload = ['nonce' => bin2hex(random_bytes(16))];
        if (!empty($conf['pkce'])) {
            $payload['code_verifier'] = $this->generateCodeVerifier();
        }
        $this->storeState($state, json_encode($payload));

        $params = [
            'client_id'     => $this->clientId($provider),
            'redirect_uri'  => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope'         => $conf['scopes'],
            'state'         => $state,
        ];
        if (!empty($conf['response_mode'])) {
            $params['response_mode'] = $conf['response_mode'];
        }
        if (in_array($provider, ['apple', 'microsoft'], true)) {
            $params['nonce'] = $payload['nonce'];
        }
        if (!empty($conf['pkce'])) {
            $params['code_challenge']        = rtrim(strtr(base64_encode(hash('sha256', $payload['code_verifier'], true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }

        return $conf['authorize'] . '?' . http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function completeLogin(string $provider, string $code, string $state, string $deviceFp, string $clientPlatform, string $language): array
    {
        $conf = $this->config($provider);

        if (empty($code)) {
            throw new \InvalidArgumentException('Authorization code required');
        }

        $stored = $this->takeState($state);
        if ($stored === null) {
            throw new \InvalidArgumentException('Invalid state');
        }
        $payload = json_decode($stored, true) ?: [];

        $tokenData = $this->exchangeCode($provider, $conf, $code, $payload['code_verifier'] ?? null);
        $profile   = $this->fetchProfile($provider, $conf, $tokenData, $payload['nonce'] ?? null);

        $email = $profile['email'] ?? null;
        if (empty($email)) {
            throw new \InvalidArgumentException('Email not provided by ' . ucfirst($provider) . '. User may need to re-authorize.');
        }
        if (empty($profile['email_verified'])) {
            throw new \InvalidArgumentException('Email not verified by ' . ucfirst($provider) . '. Please verify your email with the provider first.');
        }

        [$user, $isNew] = $this->resolveUser($profile, $language, $clientPlatform);

        $tokens = $this->auth->issueTokens($user->id, $user->role, $deviceFp, $clientPlatform);
        AuditLogger::record($isNew ? 'user_oauth_register' : 'user_oauth_login', [
            'provider' => $provider,
            'user_id'  => $user->id,
        ]);

        return $tokens;
    }

    private function exchangeCode(string $provider, array $conf, string $code, ?string $codeVerifier): array
    {
        $form = [
            'code'          => $code,
            'client_id'     => $this->clientId($provider),
            'client_secret' => $this->clientSecret($provider),
            'redirect_uri'  => $this->redirectUri($provider),
            'grant_type'    => 'authorization_code',
        ];
        if ($provider === 'apple') {
            $form['client_secret'] = $this->generateAppleClientSecret();
        }
        if ($codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        $options = ['form_params' => $form];
        if ($provider === 'github') {
            $options['headers'] = ['Accept' => 'application/json'];
        }

        $response  = $this->http->post($conf['token'], $options);
        $tokenData = json_decode((string) $response->getBody(), true) ?: [];

        if (empty($tokenData['access_token']) && empty($tokenData['id_token'])) {
            throw new \RuntimeException('Failed to obtain access token');
        }

        return $tokenData;
    }

    private function fetchProfile(string $provider, array $conf, array $tokenData, ?string $nonce = null): array
    {
        if ($provider === 'apple') {
            return $this->appleProfile($tokenData, $nonce);
        }
        if ($provider === 'microsoft') {
            return $this->microsoftProfile($tokenData, $nonce);
        }
        if ($provider === 'github') {
            return $this->githubProfile($tokenData);
        }
        if ($provider === 'x') {
            return $this->xProfile($tokenData);
        }

        $headers = ['Authorization' => 'Bearer ' . ($tokenData['access_token'] ?? '')];
        $response = $this->http->get($this->userinfoUrl($provider, $conf, $tokenData), ['headers' => $headers]);
        $info = json_decode((string) $response->getBody(), true) ?: [];

        return $this->extractProfile($provider, $info);
    }

    private function appleProfile(array $tokenData, ?string $nonce): array
    {
        if (empty($tokenData['id_token'])) {
            throw new \RuntimeException('Failed to obtain ID token');
        }
        $claims = $this->verifyOidcIdToken(
            $tokenData['id_token'],
            'https://appleid.apple.com',
            'https://appleid.apple.com/auth/keys',
            ['ES256'],
            $this->clientId('apple'),
            $nonce
        );

        return [
            'email'          => $claims['email'] ?? null,
            'nickname'       => $claims['name'] ?? null,
            'email_verified' => ($claims['email_verified'] ?? false) === true,
        ];
    }

    private function microsoftProfile(array $tokenData, ?string $nonce): array
    {
        if (empty($tokenData['id_token'])) {
            throw new \RuntimeException('Failed to obtain ID token');
        }
        $claims = $this->verifyOidcIdToken(
            $tokenData['id_token'],
            'https://login.microsoftonline.com/',
            'https://login.microsoftonline.com/common/discovery/v2.0/keys',
            ['RS256'],
            $this->clientId('microsoft'),
            $nonce
        );

        return [
            'email'          => $claims['email'] ?? $claims['upn'] ?? $claims['preferred_username'] ?? null,
            'nickname'       => $claims['name'] ?? null,
            // 只信任 provider 明确返回的布尔 true；缺省/字符串一律视为未验证
            'email_verified' => ($claims['email_verified'] ?? false) === true,
        ];
    }

    private function githubProfile(array $tokenData): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . ($tokenData['access_token'] ?? ''),
            'Accept'        => 'application/vnd.github+json',
        ];

        $user = json_decode((string) $this->http->get(self::PROVIDERS['github']['userinfo'], ['headers' => $headers])->getBody(), true) ?: [];

        $email = null;
        $emailResp = $this->http->get('https://api.github.com/user/emails', ['headers' => $headers]);
        $emails    = json_decode((string) $emailResp->getBody(), true) ?: [];
        foreach ($emails as $item) {
            if (!empty($item['primary']) && !empty($item['verified']) && !empty($item['email'])) {
                $email = $item['email'];
                break;
            }
        }
        if (!$email) {
            foreach ($emails as $item) {
                if (!empty($item['verified']) && !empty($item['email'])) {
                    $email = $item['email'];
                    break;
                }
            }
        }
        // 不回落公开资料邮箱：只有 /user/emails 中 verified 的邮箱才算已验证
        return [
            'email'          => $email,
            'nickname'       => $user['name'] ?? $user['login'] ?? null,
            'avatar'         => $user['avatar_url'] ?? null,
            'email_verified' => $email !== null,
        ];
    }

    private function xProfile(array $tokenData): array
    {
        $headers = ['Authorization' => 'Bearer ' . ($tokenData['access_token'] ?? '')];

        $response = $this->http->get(self::PROVIDERS['x']['userinfo'] . '?user.fields=profile_image_url,name,email', ['headers' => $headers]);
        $info = json_decode((string) $response->getBody(), true) ?: [];
        $data = $info['data'] ?? $info;

        $email = $data['email'] ?? null;
        if (!$email) {
            $emailResp = $this->http->get('https://api.twitter.com/2/email', ['headers' => $headers]);
            $emailData = json_decode((string) $emailResp->getBody(), true) ?: [];
            $email     = $emailData['email'] ?? null;
        }

        return [
            'email'          => $email,
            'nickname'       => $data['name'] ?? $data['username'] ?? null,
            'avatar'         => $data['profile_image_url'] ?? null,
            // X 不返回邮箱验证声明：默认不信任，避免用未验证邮箱合并账号
            'email_verified' => ($data['email_verified'] ?? $emailData['verified'] ?? false) === true,
        ];
    }

    private function verifyOidcIdToken(string $idToken, string $expectedIss, string $jwksUrl, array $algos, string $aud, ?string $nonce = null): array
    {
        $jwks   = $this->fetchJwks($jwksUrl);
        $keys   = JWK::parseKeySet($jwks);
        $claims = JWT::decode($idToken, $keys, $algos);

        $iss = (string) $claims->iss;
        if (str_ends_with($expectedIss, '/')) {
            if (!str_starts_with($iss, $expectedIss)) {
                throw new \RuntimeException('Invalid ID token issuer');
            }
        } elseif ($iss !== $expectedIss) {
            throw new \RuntimeException('Invalid ID token issuer');
        }

        if ((string) $claims->aud !== $aud) {
            throw new \RuntimeException('Invalid ID token audience');
        }

        if ($nonce !== null && ($claims->nonce ?? null) !== $nonce) {
            throw new \RuntimeException('Invalid ID token nonce');
        }

        return (array) $claims;
    }

    private function fetchJwks(string $url): array
    {
        $cacheKey = 'oauth_jwks:' . md5($url);
        $cached = Redis::get($cacheKey);
        if ($cached) {
            return json_decode($cached, true) ?: [];
        }

        $response = $this->http->get($url);
        $jwks = json_decode((string) $response->getBody(), true) ?: [];
        Redis::setex($cacheKey, 86400, json_encode($jwks));
        return $jwks;
    }

    private function userinfoUrl(string $provider, array $conf, array $tokenData): string
    {
        $url = $conf['userinfo'];
        if ($provider === 'facebook') {
            $url .= '?fields=id,name,email,email_verified,picture.type(large)&access_token=' . ($tokenData['access_token'] ?? '');
        }
        return $url;
    }

    private function extractProfile(string $provider, array $info): array
    {
        switch ($provider) {
            case 'google':
                return [
                    'email'          => $info['email'] ?? null,
                    'nickname'       => $info['name'] ?? null,
                    'avatar'         => $info['picture'] ?? null,
                    'email_verified' => ($info['email_verified'] ?? false) === true,
                ];
            case 'facebook':
                return [
                    'email'          => $info['email'] ?? null,
                    'nickname'       => $info['name'] ?? null,
                    'avatar'         => $info['picture']['data']['url'] ?? null,
                    'email_verified' => ($info['email_verified'] ?? false) === true,
                ];
            case 'linkedin':
                return [
                    'email'          => $info['email'] ?? null,
                    'nickname'       => $info['name'] ?? null,
                    'avatar'         => $info['picture'] ?? null,
                    'email_verified' => ($info['email_verified'] ?? false) === true,
                ];
        }
        return [];
    }

    /**
     * @return array{0: User, 1: bool} [user, isNew]
     */
    protected function resolveUser(array $profile, string $language, string $clientPlatform): array
    {
        // email 是 Encryptable 加密列，须按密文查询
        $user = $this->auth->findByEmail($profile['email']);
        if ($user) {
            // 已有本地账号未验证邮箱时禁止 OAuth 合并，防止预注册受害者邮箱后接管
            if (empty($user->email_verified_at)) {
                throw new \InvalidArgumentException('Local account email is not verified; verify it before OAuth login');
            }
            return [$user, false];
        }

        $this->auth->register([
            'email'    => $profile['email'],
            'password' => bin2hex(random_bytes(16)),
            'language' => $language,
            'currency' => 'USD',
        ], $clientPlatform);

        $user = $this->auth->findByEmail($profile['email']);
        // provider 已验证该邮箱：直接标记本地邮箱已验证，并清掉注册时发出的验证 token
        $user->update([
            'email_verified_at' => now(),
            'email_verify_token' => null,
            'email_verify_expires_at' => null,
        ]);
        UserProfile::where('user_id', $user->id)->update([
            'avatar'   => $profile['avatar'] ?? null,
            'nickname' => $profile['nickname'] ?? null,
        ]);

        return [$user, true];
    }

    protected function storeState(string $state, string $payload): void
    {
        Redis::setex("oauth_state:{$state}", 300, $payload);
    }

    protected function takeState(string $state): ?string
    {
        $payload = Redis::get("oauth_state:{$state}");
        if ($payload !== null) {
            Redis::del("oauth_state:{$state}");
        }
        return $payload ?: null;
    }

    private function config(string $provider): array
    {
        $provider = strtolower($provider);
        if (!isset(self::PROVIDERS[$provider])) {
            throw new \InvalidArgumentException("Unsupported OAuth provider: {$provider}");
        }
        if (empty($this->clientId($provider))) {
            throw new \InvalidArgumentException("OAuth provider not configured: {$provider}");
        }
        return self::PROVIDERS[$provider];
    }

    private function clientId(string $provider): string
    {
        return (string) getenv(strtoupper($provider) . '_OAUTH_CLIENT_ID');
    }

    private function clientSecret(string $provider): string
    {
        return (string) getenv(strtoupper($provider) . '_OAUTH_CLIENT_SECRET');
    }

    private function redirectUri(string $provider): string
    {
        return getenv(strtoupper($provider) . '_OAUTH_REDIRECT_URI') ?: url('/api/auth/' . $provider . '/callback');
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function generateAppleClientSecret(): string
    {
        $teamId   = getenv('APPLE_TEAM_ID');
        $clientId = $this->clientId('apple');
        $keyId    = getenv('APPLE_KEY_ID');
        $keyPath  = getenv('APPLE_PRIVATE_KEY_PATH');

        if (!$keyPath || !file_exists($keyPath)) {
            throw new \RuntimeException('Apple private key not found');
        }

        $payload = [
            'iss' => $teamId,
            'iat' => time(),
            'exp' => time() + 86400 * 180,
            'aud' => 'https://appleid.apple.com',
            'sub' => $clientId,
        ];

        return JWT::encode($payload, file_get_contents($keyPath), 'ES256', $keyId);
    }
}
