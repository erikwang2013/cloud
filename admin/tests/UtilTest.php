<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\common\Util;
use PHPUnit\Framework\TestCase;
use support\exception\BusinessException;

/**
 * Util 纯函数契约：此前 admin 套件对 common/Util 零覆盖。
 * 仅测无 DB/进程依赖的静态函数。
 */
final class UtilTest extends TestCase
{
    public function testPasswordHashVerifyRoundTrip(): void
    {
        $hash = Util::passwordHash('s3cret-pass');
        $this->assertNotSame('s3cret-pass', $hash);
        $this->assertTrue(Util::passwordVerify('s3cret-pass', $hash));
        $this->assertFalse(Util::passwordVerify('wrong-pass', $hash));
    }

    public function testHumanDateFallsBackToDateForFuture(): void
    {
        $future = time() + 3600;
        $this->assertSame(date('Y-m-d', $future), Util::humanDate($future));
    }

    public function testHumanDateRelativeBuckets(): void
    {
        $this->assertSame('5秒前', Util::humanDate(time() - 5));
        $this->assertSame('10分钟前', Util::humanDate(time() - 600));
        $this->assertSame('2小时前', Util::humanDate(time() - 7200));
        $this->assertSame('3天前', Util::humanDate(time() - 259200));
        $old = time() - 40 * 86400;
        $this->assertSame(date('Y-m-d', $old), Util::humanDate($old));
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0 Bytes', Util::formatBytes(0));
        $this->assertSame('1 KB', Util::formatBytes(1024));
        $this->assertSame('1.5 KB', Util::formatBytes(1536));
        $this->assertSame('1.5 MB', Util::formatBytes(1572864));
    }

    public function testCheckTableNameAcceptsSafeNames(): void
    {
        $this->assertSame('orders_2026', Util::checkTableName('orders_2026'));
    }

    public function testCheckTableNameRejectsUnsafeNames(): void
    {
        $this->expectException(BusinessException::class);
        Util::checkTableName('orders; DROP TABLE x');
    }

    public function testFilterAlphaNumPassesAndRejects(): void
    {
        $this->assertSame('abc_123', Util::filterAlphaNum('abc_123'));
        $this->expectException(BusinessException::class);
        Util::filterAlphaNum('a b');
    }

    public function testFilterNum(): void
    {
        $this->assertSame('012345', Util::filterNum('012345'));
        $this->expectException(BusinessException::class);
        Util::filterNum('12a');
    }

    public function testFilterUrlPathAcceptsHttpAndRelative(): void
    {
        $this->assertSame('https://example.com/x?y=1', Util::filterUrlPath('https://example.com/x?y=1'));
        $this->assertSame('/api/v1/orders', Util::filterUrlPath('/api/v1/orders'));
        $this->expectException(BusinessException::class);
        Util::filterUrlPath('/api/v1/orders;rm');
    }

    public function testFilterPathRejectsQueryStrings(): void
    {
        $this->assertSame('/api/v1/orders', Util::filterPath('/api/v1/orders'));
        $this->expectException(BusinessException::class);
        Util::filterPath('/api/v1/orders?x=1');
    }

    public function testControllerToUrlPath(): void
    {
        $this->assertSame('order/index', Util::controllerToUrlPath('app\controller\OrderController@index'));
        $this->assertSame('order', Util::controllerToUrlPath('app\controller\OrderController'));
        $this->assertFalse(Util::controllerToUrlPath('OrderController'));
    }

    public function testCamelAndSmCamel(): void
    {
        $this->assertSame('OrderItemId', Util::camel('order_item_id'));
        $this->assertSame('orderItemId', Util::smCamel('order_item_id'));
    }

    public function testGetCommentFirstLine(): void
    {
        $doc = "/**\n * 第一行说明\n * 第二行\n */";
        $this->assertSame('第一行说明', Util::getCommentFirstLine($doc));
        $this->assertFalse(Util::getCommentFirstLine(false));
    }

    public function testTypeToControl(): void
    {
        $this->assertSame('inputNumber', Util::typeToControl('bigint'));
        $this->assertSame('dateTimePicker', Util::typeToControl('datetime'));
        $this->assertSame('textArea', Util::typeToControl('mediumtext'));
        $this->assertSame('select', Util::typeToControl('enum'));
        $this->assertSame('input', Util::typeToControl('varchar'));
    }

    public function testTypeToMethod(): void
    {
        $this->assertSame('bigInteger', Util::typeToMethod('bigint'));
        $this->assertSame('unsignedBigInteger', Util::typeToMethod('bigint', true));
        $this->assertSame('string', Util::typeToMethod('varchar'));
        $this->assertSame('dateTime', Util::typeToMethod('datetime'));
    }

    public function testGetLengthValue(): void
    {
        $decimal = (object) ['DATA_TYPE' => 'decimal', 'NUMERIC_PRECISION' => 10, 'NUMERIC_SCALE' => 2];
        $this->assertSame('10,2', Util::getLengthValue($decimal));

        $enum = (object) ['DATA_TYPE' => 'enum', 'COLUMN_TYPE' => "enum('a','b')"];
        $this->assertSame('a,b', Util::getLengthValue($enum));

        $varchar = (object) ['DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 255];
        $this->assertSame(255, Util::getLengthValue($varchar));
    }

    public function testGetControlProps(): void
    {
        // select 控件的 data 参数转 value/name 列表
        $props = Util::getControlProps('select', 'data:1:香港,2:美国;size:lg');
        $this->assertSame(
            [['value' => '1', 'name' => '香港'], ['value' => '2', 'name' => '美国']],
            $props['data']
        );
        $this->assertSame('lg', $props['size']);

        // 非列表控件保持 key => value 映射
        $input = Util::getControlProps('input', 'data:1:香港');
        $this->assertSame(['1' => '香港'], $input['data']);

        $this->assertSame([], Util::getControlProps('input', ''));
    }
}
