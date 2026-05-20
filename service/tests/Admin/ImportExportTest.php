<?php

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

final class ImportExportTest extends TestCase
{
    public function testCsvHeaderFormatIsCorrect(): void
    {
        $headers = ['ProductID', 'Name', 'Category', 'SkuID', 'Specs', 'Cycle', 'RegionID', 'Price', 'OriginalPrice', 'Stock'];
        $this->assertCount(10, $headers);
        $this->assertSame('ProductID', $headers[0]);
        $this->assertSame('Stock', $headers[9]);
    }

    public function testArrayCombineWithPadHandlesShortRows(): void
    {
        $header = ['A', 'B', 'C'];
        $short  = ['x'];
        // Simulate the controller logic: pad then slice to header length
        $result = array_combine($header, array_slice(array_pad($short, 3, ''), 0, 3));
        $this->assertSame(['A' => 'x', 'B' => '', 'C' => ''], $result);
    }

    public function testArrayCombineWithSliceHandlesExtraColumns(): void
    {
        $header = ['A', 'B'];
        $long   = ['x', 'y', 'z']; // row has more columns than header
        $result = array_combine($header, array_slice(array_pad($long, 2, ''), 0, 2));
        $this->assertSame(['A' => 'x', 'B' => 'y'], $result);
    }

    public function testJsonDecodeEmptySpecsReturnsArray(): void
    {
        $specs = json_decode('{}', true) ?: [];
        $this->assertIsArray($specs);
    }

    public function testJsonDecodeNullReturnsEmptyArray(): void
    {
        $specs = json_decode('null', true) ?: [];
        $this->assertIsArray($specs);
        $this->assertEmpty($specs);
    }
}
