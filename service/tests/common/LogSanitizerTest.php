<?php

namespace Tests\common;

use Common\security\LogSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogSanitizerTest extends TestCase
{
    public function testSanitizeRedactsPasswordFields(): void
    {
        $data = ['username' => 'john', 'password' => 'secret123'];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('john', $result['username']);
        $this->assertSame('***REDACTED***', $result['password']);
    }

    public function testSanitizeRedactsPasswordHash(): void
    {
        $data = ['password_hash' => '$2y$12$abcdef...'];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['password_hash']);
    }

    public function testSanitizeRedactsTokenFields(): void
    {
        $data = [
            'access_token'  => 'eyJhbGciOi...',
            'refresh_token' => 'def50200...',
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['access_token']);
        $this->assertSame('***REDACTED***', $result['refresh_token']);
    }

    public function testSanitizeRedactsApiKeyAndSecret(): void
    {
        $data = [
            'api_key'    => 'sk-live-abc123',
            'api_secret' => 'shhh-secret',
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['api_key']);
        $this->assertSame('***REDACTED***', $result['api_secret']);
    }

    public function testSanitizeRedactsCreditCardFields(): void
    {
        $data = [
            'credit_card' => '4111111111111111',
            'card_number' => '5555555555554444',
            'cvv'         => '123',
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['credit_card']);
        $this->assertSame('***REDACTED***', $result['card_number']);
        $this->assertSame('***REDACTED***', $result['cvv']);
    }

    public function testSanitizeRedactsPiiFields(): void
    {
        $data = [
            'real_name' => 'John Doe',
            'id_number' => '440123199001011234',
            'ssn'       => '123-45-6789',
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['real_name']);
        $this->assertSame('***REDACTED***', $result['id_number']);
        $this->assertSame('***REDACTED***', $result['ssn']);
    }

    public function testSanitizeRedactsPrivateKey(): void
    {
        $data = ['private_key' => '-----BEGIN RSA PRIVATE KEY-----...'];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['private_key']);
    }

    public function testSanitizeHandlesNestedArrays(): void
    {
        $data = [
            'user' => [
                'name'     => 'John',
                'password' => 'nested-secret',
                'profile'  => [
                    'api_key' => 'nested-key',
                    'bio'     => 'A developer',
                ],
            ],
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('John', $result['user']['name']);
        $this->assertSame('***REDACTED***', $result['user']['password']);
        $this->assertSame('***REDACTED***', $result['user']['profile']['api_key']);
        $this->assertSame('A developer', $result['user']['profile']['bio']);
    }

    public function testSanitizeCaseInsensitiveFieldMatching(): void
    {
        $data = [
            'Password'   => 'UpperSecret',
            'API_KEY'    => 'UpperKey',
            'AccessToken' => 'UpperToken',
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['Password']);
        $this->assertSame('***REDACTED***', $result['API_KEY']);
        $this->assertSame('***REDACTED***', $result['AccessToken']);
    }

    public function testSanitizeSubstringMatchOnFieldNames(): void
    {
        // "token" appears in "my_secret_token_field"
        $data = ['my_secret_token_field' => 'sensitive'];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame('***REDACTED***', $result['my_secret_token_field']);
    }

    public function testSanitizeLeavesSafeFieldsUntouched(): void
    {
        $data = [
            'username'  => 'john_doe',
            'email'     => 'john@example.com',
            'full_name' => 'John Doe',
            'bio'       => 'Hello world',
            'age'       => 30,
        ];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame($data, $result);
    }

    public function testSanitizeHandlesEmptyArray(): void
    {
        $result = LogSanitizer::sanitize([]);

        $this->assertSame([], $result);
    }

    public function testSanitizeHandlesDataWithoutSensitiveFields(): void
    {
        $data = ['id' => 42, 'status' => 'active', 'count' => 100];

        $result = LogSanitizer::sanitize($data);

        $this->assertSame($data, $result);
    }

    #[DataProvider('sensitiveFieldProvider')]
    public function testKnownSensitiveFieldsAreRedacted(string $field): void
    {
        $result = LogSanitizer::sanitize([$field => 'sensitive_value']);

        $this->assertSame('***REDACTED***', $result[$field]);
    }

    public static function sensitiveFieldProvider(): array
    {
        return [
            'password'              => ['password'],
            'password_hash'         => ['password_hash'],
            'password_confirmation' => ['password_confirmation'],
            'secret'                => ['secret'],
            'api_key'               => ['api_key'],
            'api_secret'            => ['api_secret'],
            'api_token'             => ['api_token'],
            'token'                 => ['token'],
            'access_token'          => ['access_token'],
            'refresh_token'         => ['refresh_token'],
            'credit_card'           => ['credit_card'],
            'cvv'                   => ['cvv'],
            'card_number'           => ['card_number'],
            'ssn'                   => ['ssn'],
            'id_number'             => ['id_number'],
            'real_name'             => ['real_name'],
            'login_password'        => ['login_password'],
            'private_key'           => ['private_key'],
            'auth_code'             => ['auth_code'],
            'answer'                => ['answer'],
        ];
    }

    public function testSanitizeRedactsFieldContainingPasswordAsSubstring(): void
    {
        // "new_password" contains "password", should be redacted
        $result = LogSanitizer::sanitize(['new_password' => 'value']);

        $this->assertSame('***REDACTED***', $result['new_password']);
    }
}
