<?php

namespace Tests\User;

use App\User\Model\RefreshToken;
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
