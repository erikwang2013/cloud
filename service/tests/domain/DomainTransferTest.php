<?php

namespace Tests\domain;

use App\domain\model\DomainTransfer;
use PHPUnit\Framework\TestCase;

final class DomainTransferTest extends TestCase
{
    public function testAuthCodeNeverSerialized(): void
    {
        $hidden = (new DomainTransfer())->getHidden();
        $this->assertContains('auth_code_encrypted', $hidden);
    }
}
