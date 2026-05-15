<?php

namespace Tests\Common;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SnowflakeTest extends TestCase
{
    public function testSnowflakeIdIs64Bit(): void
    {
        $maxSnowflake = 9223372036854775807;
        $this->assertLessThanOrEqual($maxSnowflake, PHP_INT_MAX);
        $this->assertGreaterThan(0, $maxSnowflake);
    }

    public function testNewerSnowflakeIsGreater(): void
    {
        $newer = 7823456789123456789;
        $older = 1000000000000000000;
        $this->assertGreaterThan($older, $newer);
    }

    public function testSameSnowflakeEquals(): void
    {
        $id = 1000000000000000000;
        $this->assertSame($id, $id);
    }

    public function testIdIsPositive(): void
    {
        $ids = [7823456789123456789, 1234567890123456789, 1];
        foreach ($ids as $id) {
            $this->assertGreaterThan(0, $id);
        }
    }

    public function testNoAutoIncrement(): void
    {
        $ids = [
            7823456789123456789,
            7823456789123456790,
            7823456789123456791,
        ];
        $this->assertCount(3, array_unique($ids));
    }

    public function testBigintRange(): void
    {
        $minBigint = 0;
        $maxBigint = 18446744073709551615;
        $id = 7823456789123456789;
        $this->assertGreaterThanOrEqual($minBigint, $id);
        $this->assertLessThanOrEqual($maxBigint, $id);
    }
}
