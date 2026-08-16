<?php

namespace Tests\User;

use App\User\Model\UserKyc;
use PHPUnit\Framework\TestCase;

final class UserKycTest extends TestCase
{
    public function testIdNumberNeverSerialized(): void
    {
        $hidden = (new UserKyc())->getHidden();
        $this->assertContains('id_number_encrypted', $hidden);
    }
}
