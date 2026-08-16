<?php

namespace Tests\Domain;

use App\Domain\Model\DomainTransfer;
use PHPUnit\Framework\TestCase;

final class DomainTransferTest extends TestCase
{
    public function testAuthCodeNeverSerialized(): void
    {
        $hidden = (new DomainTransfer())->getHidden();
        $this->assertContains('auth_code_encrypted', $hidden);
    }
}
