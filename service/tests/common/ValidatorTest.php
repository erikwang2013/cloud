<?php

namespace Tests\common;

use Common\helper\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    // ── required ────────────────────────────────────────────────

    public function testRequiredReturnsNullWhenAllFieldsPresent(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];

        $result = Validator::required($data, ['name', 'email']);

        $this->assertNull($result);
    }

    public function testRequiredReturnsMissingFieldName(): void
    {
        $data = ['name' => 'John'];

        $result = Validator::required($data, ['name', 'email']);

        $this->assertSame('email', $result);
    }

    public function testRequiredTreatsEmptyStringAsMissing(): void
    {
        $data = ['name' => '', 'email' => 'john@example.com'];

        $result = Validator::required($data, ['name', 'email']);

        $this->assertSame('name', $result);
    }

    public function testRequiredTreatsZeroAsEmpty(): void
    {
        // PHP's empty() considers 0, '0', false, null, '' all empty
        $data = ['count' => 0];

        $result = Validator::required($data, ['count']);

        $this->assertSame('count', $result);
    }

    public function testRequiredReturnsFirstMissingField(): void
    {
        $data = [];

        $result = Validator::required($data, ['first_name', 'last_name', 'email']);

        $this->assertSame('first_name', $result);
    }

    // ── email ───────────────────────────────────────────────────

    public function testEmailValidatesStandardAddress(): void
    {
        $this->assertTrue(Validator::email('user@example.com'));
    }

    public function testEmailValidatesSubdomainAddress(): void
    {
        $this->assertTrue(Validator::email('user@mail.example.co.uk'));
    }

    public function testEmailValidatesPlusAddress(): void
    {
        $this->assertTrue(Validator::email('user+tag@example.com'));
    }

    public function testEmailRejectsMissingAt(): void
    {
        $this->assertFalse(Validator::email('userexample.com'));
    }

    public function testEmailRejectsMissingDomain(): void
    {
        $this->assertFalse(Validator::email('user@'));
    }

    public function testEmailRejectsEmptyString(): void
    {
        $this->assertFalse(Validator::email(''));
    }

    #[DataProvider('emailProvider')]
    public function testEmailValidation(string $email, bool $expected): void
    {
        $this->assertSame($expected, Validator::email($email));
    }

    public static function emailProvider(): array
    {
        return [
            'valid simple'       => ['john@doe.com', true],
            'valid with dots'    => ['john.doe@company.org', true],
            'unicode idn not supported' => ['user@bücher.de', false],
            'invalid no at'      => ['notanemail', false],
            'invalid double at'  => ['a@b@c.com', false],
            'invalid spaces'     => ['user @example.com', false],
        ];
    }

    // ── minLength ───────────────────────────────────────────────

    public function testMinLengthPassesWhenEqual(): void
    {
        $this->assertTrue(Validator::minLength('abcd', 4));
    }

    public function testMinLengthPassesWhenLonger(): void
    {
        $this->assertTrue(Validator::minLength('abcde', 4));
    }

    public function testMinLengthFailsWhenShorter(): void
    {
        $this->assertFalse(Validator::minLength('ab', 4));
    }

    public function testMinLengthWithMultibyteCharacters(): void
    {
        $this->assertTrue(Validator::minLength('中文测试', 4));
        $this->assertFalse(Validator::minLength('中文', 4));
    }

    public function testMinLengthWithZeroAlwaysPasses(): void
    {
        $this->assertTrue(Validator::minLength('', 0));
    }
}
