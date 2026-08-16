<?php

namespace Tests\Storage;

use App\Storage\Model\StorageBucket;
use PHPUnit\Framework\TestCase;

final class StorageBucketTest extends TestCase
{
    public function testCredentialFieldsNeverSerialized(): void
    {
        $hidden = (new StorageBucket())->getHidden();
        $this->assertContains('access_key_encrypted', $hidden);
        $this->assertContains('secret_key_encrypted', $hidden);
    }
}
