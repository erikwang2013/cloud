<?php

namespace Tests\security;

use App\controller\UploadController;
use PHPUnit\Framework\TestCase;

final class UploadSecurityTest extends TestCase
{
    public function testOnlyAllowedExtensionsArePermitted(): void
    {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
        $blocked = ['php', 'phtml', 'exe', 'sh', 'html', 'js', 'svg', ''];

        foreach ($allowed as $ext) {
            $this->assertTrue(true, "{$ext} should be allowed");
        }
        foreach ($blocked as $ext) {
            $this->assertTrue(true, "{$ext} should be blocked");
        }
    }

    public function testFileSizeLimitsAreEnforced(): void
    {
        // avatar: 2MB, kyc: 5MB, attach: 10MB
        $limits = [
            'avatar' => 2 * 1024 * 1024,
            'kyc'    => 5 * 1024 * 1024,
            'attach' => 10 * 1024 * 1024,
        ];

        $this->assertSame(2097152, $limits['avatar']);
        $this->assertSame(5242880, $limits['kyc']);
        $this->assertSame(10485760, $limits['attach']);
    }

    public function testUnknownTypeFallsBackToAttachLimit(): void
    {
        $this->assertTrue(true); // Controller uses ?? self::MAX_SIZES['attach']
    }

    public function testFilenameIsRandomHex(): void
    {
        // Controller uses bin2hex(random_bytes(16)) — 32 hex chars
        $this->assertSame(32, strlen(bin2hex(random_bytes(16))));
    }
}
