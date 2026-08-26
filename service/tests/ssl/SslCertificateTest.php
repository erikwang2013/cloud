<?php

namespace Tests\ssl;

use App\ssl\model\SslCertificate;
use PHPUnit\Framework\TestCase;

final class SslCertificateTest extends TestCase
{
    public function testPrivateKeyNeverSerialized(): void
    {
        $hidden = (new SslCertificate())->getHidden();
        $this->assertContains('private_key_encrypted', $hidden);
    }
}
