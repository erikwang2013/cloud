<?php

namespace Tests\payment;

use App\payment\model\PaymentChannel;
use PHPUnit\Framework\TestCase;

final class PaymentChannelTest extends TestCase
{
    public function testSecretFieldsNeverSerialized(): void
    {
        // Encryptable cast 在 toArray/toJson 自动解密，密钥字段必须从序列化排除
        $hidden = (new PaymentChannel())->getHidden();
        $this->assertContains('api_key_encrypted', $hidden);
        $this->assertContains('webhook_secret', $hidden);
    }
}
