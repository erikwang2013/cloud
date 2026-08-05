<?php

namespace Tests\User;

use App\User\Service\AuthService;
use App\User\Service\OAuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    private const ENV_KEYS = [
        'GOOGLE_OAUTH_CLIENT_ID',
        'GOOGLE_OAUTH_CLIENT_SECRET',
        'GOOGLE_OAUTH_REDIRECT_URI',
        'FACEBOOK_OAUTH_CLIENT_ID',
        'FACEBOOK_OAUTH_CLIENT_SECRET',
        'FACEBOOK_OAUTH_REDIRECT_URI',
        'X_OAUTH_CLIENT_ID',
        'X_OAUTH_CLIENT_SECRET',
        'X_OAUTH_REDIRECT_URI',
        'GITHUB_OAUTH_CLIENT_ID',
        'GITHUB_OAUTH_CLIENT_SECRET',
        'GITHUB_OAUTH_REDIRECT_URI',
    ];

    protected function setUp(): void
    {
        putenv('GOOGLE_OAUTH_CLIENT_ID=google-client-id');
        putenv('GOOGLE_OAUTH_CLIENT_SECRET=google-client-secret');
        putenv('GOOGLE_OAUTH_REDIRECT_URI=https://api.example.com/api/auth/google/callback');
        putenv('FACEBOOK_OAUTH_CLIENT_ID=fb-client-id');
        putenv('FACEBOOK_OAUTH_CLIENT_SECRET=fb-client-secret');
        putenv('FACEBOOK_OAUTH_REDIRECT_URI=https://api.example.com/api/auth/facebook/callback');
        putenv('X_OAUTH_CLIENT_ID=x-client-id');
        putenv('X_OAUTH_CLIENT_SECRET=x-client-secret');
        putenv('X_OAUTH_REDIRECT_URI=https://api.example.com/api/auth/x/callback');
        putenv('GITHUB_OAUTH_CLIENT_ID=github-client-id');
        putenv('GITHUB_OAUTH_CLIENT_SECRET=github-client-secret');
        putenv('GITHUB_OAUTH_REDIRECT_URI=https://api.example.com/api/auth/github/callback');
    }

    protected function tearDown(): void
    {
        foreach (self::ENV_KEYS as $key) {
            putenv($key);
        }
    }

    public function testUnknownProviderThrows(): void
    {
        $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported OAuth provider');
        $service->authorizeUrl('foo');
    }

    public function testUnconfiguredProviderThrows(): void
    {
        $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OAuth provider not configured');
        $service->authorizeUrl('linkedin');
    }

    public function testAuthorizeUrlBuildsGoogleUrl(): void
    {
        $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
        $url = $service->authorizeUrl('google');

        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', $this->schemeHostPath($url));
        $this->assertSame('google-client-id', $this->queryParam($url, 'client_id'));
        $this->assertSame('code', $this->queryParam($url, 'response_type'));
        $this->assertSame('https://api.example.com/api/auth/google/callback', $this->queryParam($url, 'redirect_uri'));
        $this->assertStringContainsString('email', $this->queryParam($url, 'scope'));
        $this->assertNotEmpty($this->queryParam($url, 'state'));
        $this->assertNotSame('', $service->storedPayload);
        $payload = json_decode($service->storedPayload, true);
        $this->assertNotEmpty($payload['nonce']);
    }

    public function testAuthorizeUrlSendsNonceForAppleAndMicrosoft(): void
    {
        putenv('APPLE_OAUTH_CLIENT_ID=apple-client-id');
        putenv('APPLE_OAUTH_REDIRECT_URI=https://api.example.com/api/auth/apple/callback');
        putenv('MICROSOFT_OAUTH_CLIENT_ID=ms-client-id');
        putenv('MICROSOFT_OAUTH_REDIRECT_URI=https://api.example.com/api/auth/microsoft/callback');
        try {
            $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
            foreach (['apple', 'microsoft'] as $provider) {
                $url = $service->authorizeUrl($provider);
                $nonce = $this->queryParam($url, 'nonce');
                $this->assertNotEmpty($nonce, "$provider should send nonce");
                $payload = json_decode($service->storedPayload, true);
                $this->assertSame($nonce, $payload['nonce']);
            }
        } finally {
            putenv('APPLE_OAUTH_CLIENT_ID');
            putenv('APPLE_OAUTH_REDIRECT_URI');
            putenv('MICROSOFT_OAUTH_CLIENT_ID');
            putenv('MICROSOFT_OAUTH_REDIRECT_URI');
        }
    }

    public function testAuthorizeUrlIncludesPkceForX(): void
    {
        $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
        $url = $service->authorizeUrl('x');

        $this->assertSame('S256', $this->queryParam($url, 'code_challenge_method'));
        $this->assertNotEmpty($this->queryParam($url, 'code_challenge'));
        $payload = json_decode($service->storedPayload, true);
        $this->assertNotEmpty($payload['code_verifier']);
    }

    public function testCompleteLoginRequiresCode(): void
    {
        $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Authorization code required');
        $service->completeLogin('google', '', 'state', 'fp', 'web', 'en-US');
    }

    public function testCompleteLoginRejectsInvalidState(): void
    {
        $service = new TestableOAuthService(new FakeAuthService(), $this->httpClient([]));
        $service->storedPayload = null;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid state');
        $service->completeLogin('google', 'code', 'unknown-state', 'fp', 'web', 'en-US');
    }

    public function testGoogleHappyPath(): void
    {
        $http = $this->httpClient([
            new Response(200, [], json_encode(['access_token' => 'tok-123', 'expires_in' => 3600])),
            new Response(200, [], json_encode([
                'email'         => 'user@gmail.com',
                'name'          => 'Test User',
                'picture'       => 'https://example.com/avatar.png',
                'email_verified' => true,
            ])),
        ]);

        $service = new TestableOAuthService(new FakeAuthService(), $http);
        $service->storedPayload = json_encode(['nonce' => 'abc']);

        $tokens = $service->completeLogin('google', 'auth-code', 'state', 'fp', 'web', 'en-US');

        $this->assertSame('access-token', $tokens['access_token']);
        $this->assertSame('user@gmail.com', $service->resolvedProfile['email']);
        $this->assertSame('Test User', $service->resolvedProfile['nickname']);
    }

    public function testGoogleUnverifiedEmailRejected(): void
    {
        $http = $this->httpClient([
            new Response(200, [], json_encode(['access_token' => 'tok-123', 'expires_in' => 3600])),
            new Response(200, [], json_encode([
                'email'         => 'unverified@gmail.com',
                'name'          => 'Unverified User',
                'email_verified' => false,
            ])),
        ]);

        $service = new TestableOAuthService(new FakeAuthService(), $http);
        $service->storedPayload = json_encode(['nonce' => 'abc']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email not verified by Google');
        $service->completeLogin('google', 'auth-code', 'state', 'fp', 'web', 'en-US');
    }

    public function testXFallsBackToEmailEndpoint(): void
    {
        $http = $this->httpClient([
            new Response(200, [], json_encode(['access_token' => 'x-token', 'expires_in' => 3600])),
            new Response(200, [], json_encode([
                'data' => ['id' => '1', 'name' => 'X User', 'username' => 'xuser', 'profile_image_url' => 'https://example.com/x.png'],
            ])),
            new Response(200, [], json_encode(['email' => 'xuser@example.com'])),
        ]);

        $service = new TestableOAuthService(new FakeAuthService(), $http);
        $service->storedPayload = json_encode(['nonce' => 'abc', 'code_verifier' => 'cv']);

        $tokens = $service->completeLogin('x', 'auth-code', 'state', 'fp', 'web', 'en-US');

        $this->assertSame('access-token', $tokens['access_token']);
        $this->assertSame('xuser@example.com', $service->resolvedProfile['email']);
        $this->assertTrue($service->resolvedProfile['email_verified']);
    }

    public function testGithubFallsBackToEmailsEndpoint(): void
    {
        $http = $this->httpClient([
            new Response(200, [], json_encode(['access_token' => 'gh-token'])),
            new Response(200, [], json_encode(['id' => 1, 'login' => 'octocat', 'email' => null])),
            new Response(200, [], json_encode([
                ['email' => 'noreply@users.noreply.github.com', 'primary' => false, 'verified' => true],
                ['email' => 'octocat@example.com', 'primary' => true, 'verified' => true],
            ])),
        ]);

        $service = new TestableOAuthService(new FakeAuthService(), $http);
        $service->storedPayload = json_encode(['nonce' => 'abc']);

        $service->completeLogin('github', 'code', 'state', 'fp', 'web', 'en-US');

        $this->assertSame('octocat@example.com', $service->resolvedProfile['email']);
        $this->assertSame('octocat', $service->resolvedProfile['nickname']);
    }

    public function testFacebookWithoutEmailThrows(): void
    {
        $http = $this->httpClient([
            new Response(200, [], json_encode(['access_token' => 'fb-token'])),
            new Response(200, [], json_encode(['id' => '123', 'name' => 'No Mail User'])),
        ]);

        $service = new TestableOAuthService(new FakeAuthService(), $http);
        $service->storedPayload = json_encode(['nonce' => 'abc']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email not provided by Facebook');
        $service->completeLogin('facebook', 'code', 'state', 'fp', 'web', 'en-US');
    }

    private function httpClient(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    private function schemeHostPath(string $url): string
    {
        return parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH);
    }

    private function queryParam(string $url, string $name): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        return $params[$name] ?? null;
    }
}

class TestableOAuthService extends OAuthService
{
    public ?string $storedPayload = null;
    public ?array $resolvedProfile = null;

    protected function storeState(string $state, string $payload): void
    {
        $this->storedPayload = $payload;
    }

    protected function takeState(string $state): ?string
    {
        return $this->storedPayload;
    }

    protected function resolveUser(array $profile, string $language, string $clientPlatform): array
    {
        $this->resolvedProfile = $profile;
        return [(object) ['id' => 42, 'role' => 'user'], false];
    }
}

class FakeAuthService extends AuthService
{
    public function __construct()
    {
        // Skip parent constructor (JWT config requires the app container)
    }

    public function issueTokens(int $userId, string $role, string $deviceFingerprint = '', string $clientPlatform = ''): array
    {
        return [
            'access_token'  => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in'    => 900,
            'token_type'    => 'Bearer',
        ];
    }
}
