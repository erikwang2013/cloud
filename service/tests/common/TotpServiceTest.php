<?php

namespace Tests\common;

use Common\auth\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function testGenerateSecretReturnsNonEmptyString(): void
    {
        $secret = TotpService::generateSecret();
        $this->assertIsString($secret);
        $this->assertNotEmpty($secret);
    }

    public function testGenerateSecretIsUnique(): void
    {
        $a = TotpService::generateSecret();
        $b = TotpService::generateSecret();
        $this->assertNotSame($a, $b);
    }

    public function testGenerateSecretOnlyBase32Characters(): void
    {
        $secret = TotpService::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGetQrUrlContainsSecret(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $url    = TotpService::getQrUrl('test@example.com', $secret);
        $this->assertStringContainsString('otpauth://totp/', $url);
        $this->assertStringContainsString(rawurlencode('CloudPlatform:test@example.com'), $url);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $url);
    }

    public function testVerifyRejectsShortCode(): void
    {
        $this->assertFalse(TotpService::verify('JBSWY3DPEHPK3PXP', '12345'));
    }

    public function testVerifyRejectsLongCode(): void
    {
        $this->assertFalse(TotpService::verify('JBSWY3DPEHPK3PXP', '1234567'));
    }

    public function testVerifyRejectsNonNumericCode(): void
    {
        $this->assertFalse(TotpService::verify('JBSWY3DPEHPK3PXP', 'abc123'));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $secret = TotpService::generateSecret();
        $this->assertFalse(TotpService::verify($secret, '000000'));
    }

    public function testBase32RoundTrip(): void
    {
        $original = random_bytes(20);
        $encoded  = $this->invokeStatic('Common\auth\TotpService', 'base32Encode', $original);
        $decoded  = $this->invokeStatic('Common\auth\TotpService', 'base32Decode', $encoded);
        $this->assertSame($original, $decoded);
    }

    public function testTOTPProducesSixDigits(): void
    {
        $key       = random_bytes(20);
        $timeSlice = (int) floor(time() / 30);
        $code      = $this->invokeStatic('Common\auth\TotpService', 'totp', $key, $timeSlice);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testTOTPIsDeterministic(): void
    {
        $key       = random_bytes(20);
        $timeSlice = 12345;
        $a = $this->invokeStatic('Common\auth\TotpService', 'totp', $key, $timeSlice);
        $b = $this->invokeStatic('Common\auth\TotpService', 'totp', $key, $timeSlice);
        $this->assertSame($a, $b);
    }

    public function testVerifyPassesForCurrentWindow(): void
    {
        $secret    = TotpService::generateSecret();
        $key       = $this->invokeStatic('Common\auth\TotpService', 'base32Decode', $secret);
        $timeSlice = (int) floor(time() / 30);
        $code      = $this->invokeStatic('Common\auth\TotpService', 'totp', $key, $timeSlice);

        $this->assertTrue(TotpService::verify($secret, $code));
    }

    private function invokeStatic(string $class, string $method, ...$args): mixed
    {
        $ref = new \ReflectionMethod($class, $method);
        return $ref->invoke(null, ...$args);
    }
}
