<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

declare(strict_types=1);

namespace tests;

use app\common\ExcelExport;
use PHPUnit\Framework\TestCase;

final class ExcelExportTest extends TestCase
{
    private function sheet(ExcelExport $export): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $prop = new \ReflectionProperty(ExcelExport::class, 'spreadsheet');
        $prop->setAccessible(true);
        return $prop->getValue($export)->getActiveSheet();
    }

    public function testSetColumnsWritesBoldHeaders(): void
    {
        $export = new ExcelExport('订单导出');
        $export->setColumns(['order_no', 'total'], ['order_no' => '订单号', 'total' => '金额']);

        $sheet = $this->sheet($export);
        $this->assertSame('订单号', $sheet->getCell('A1')->getValue());
        $this->assertSame('金额', $sheet->getCell('B1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    public function testAddRowFlattensArraysToJson(): void
    {
        $export = new ExcelExport('Export');
        $export->setColumns(['id', 'tags'], ['id' => 'ID', 'tags' => '标签']);
        $export->addRow(['id' => 7, 'tags' => ['a', 'b']]);

        $sheet = $this->sheet($export);
        $this->assertSame('7', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('["a","b"]', $sheet->getCell('B2')->getValue());
    }

    public function testAddRowsSequentialRowIndex(): void
    {
        $export = new ExcelExport('Export');
        $export->setColumns(['id']);
        $export->addRows([['id' => 1], ['id' => 2], ['id' => 3]]);

        $sheet = $this->sheet($export);
        $this->assertSame('1', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame('2', (string) $sheet->getCell('A3')->getValue());
        $this->assertSame('3', (string) $sheet->getCell('A4')->getValue());
    }

    public function testMissingColumnKeyBecomesEmptyCell(): void
    {
        $export = new ExcelExport('Export');
        $export->setColumns(['id', 'extra']);
        $export->addRow(['id' => 1]);

        $sheet = $this->sheet($export);
        $this->assertSame('', $sheet->getCell('B2')->getValue());
    }
}
