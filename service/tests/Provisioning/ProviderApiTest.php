<?php

namespace Tests\Provisioning;

use App\Provisioning\Model\ProviderApi;
use PHPUnit\Framework\TestCase;

final class ProviderApiTest extends TestCase
{
    public function testCredentialFieldsNeverSerialized(): void
    {
        $hidden = (new ProviderApi())->getHidden();
        $this->assertContains('api_key_encrypted', $hidden);
        $this->assertContains('api_secret_encrypted', $hidden);
        $this->assertContains('webhook_secret', $hidden);
    }
}
