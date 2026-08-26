<?php

namespace Tests\common;

use Common\snowflake\SnowflakeService;
use PHPUnit\Framework\TestCase;

final class SnowflakeTest extends TestCase
{
    public function testNextIdReturnsPositiveInteger(): void
    {
        $id = SnowflakeService::nextId();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testSuccessiveIdsAreMonotonicallyIncreasing(): void
    {
        $a = SnowflakeService::nextId();
        $b = SnowflakeService::nextId();
        $this->assertGreaterThan($a, $b);
    }

    public function testRapidCallsProduceUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = SnowflakeService::nextId();
        }
        $this->assertCount(count($ids), array_unique($ids));
    }

    public function testIdFitsIn64BitSignedRange(): void
    {
        $id = SnowflakeService::nextId();
        $this->assertGreaterThan(0, $id);
        $this->assertLessThanOrEqual(PHP_INT_MAX, $id);
    }

    public function testInitReturnsSnowflakeInstance(): void
    {
        $snowflake = SnowflakeService::init();
        $this->assertInstanceOf(\Erikwang2013\Snowflake\Snowflake::class, $snowflake);
    }

    public function testInitReturnsSameInstance(): void
    {
        $this->assertSame(SnowflakeService::init(), SnowflakeService::init());
    }
}
