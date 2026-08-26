<?php

namespace Tests\user;

use App\user\model\RefreshToken;
use PHPUnit\Framework\TestCase;

final class RefreshTokenTest extends TestCase
{
    public function testTokenHashNeverSerialized(): void
    {
        $hidden = (new RefreshToken())->getHidden();
        $this->assertContains('token_hash', $hidden);
        $this->assertContains('device_fingerprint', $hidden);
    }
}
