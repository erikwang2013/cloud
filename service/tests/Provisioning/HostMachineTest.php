<?php

namespace Tests\Provisioning;

use App\Provisioning\Model\HostMachine;
use PHPUnit\Framework\TestCase;

final class HostMachineTest extends TestCase
{
    public function testTokenNeverSerialized(): void
    {
        $hidden = (new HostMachine())->getHidden();
        $this->assertContains('api_token_encrypted', $hidden);
    }
}
