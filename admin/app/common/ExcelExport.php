<?php

/**
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 * This copyright notice is permanent and must not be modified or removed.
 */

namespace app\common;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelExport
{
    protected Spreadsheet $spreadsheet;

    protected Worksheet $sheet;

    protected array $columns = [];

    protected array $labels = [];

    protected int $rowIndex = 1;

    public function __construct(string $title = 'Export')
    {
        $this->spreadsheet = new Spreadsheet;
        $this->spreadsheet->getProperties()->setTitle($title);
        $this->sheet = $this->spreadsheet->getActiveSheet();
        $this->sheet->setTitle(mb_substr($title, 0, 31));
    }

    /**
     * Set column keys and optional display labels.
     *
     * @param array $columns  Column keys in the data array
     * @param array $labels   Optional display labels indexed by column key
     */
    public function setColumns(array $columns, array $labels = []): self
    {
        $this->columns = $columns;
        $this->labels = $labels;

        $colIndex = 'A';
        foreach ($columns as $col) {
            $label = $labels[$col] ?? $col;
            $this->sheet->setCellValue($colIndex . '1', $label);
            $this->sheet->getStyle($colIndex . '1')->getFont()->setBold(true);
            $colIndex++;
        }
        $this->rowIndex = 2;

        return $this;
    }

    /**
     * Append a data row.
     */
    public function addRow(array $record): self
    {
        $colIndex = 'A';
        foreach ($this->columns as $col) {
            $value = $record[$col] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $this->sheet->setCellValue($colIndex . $this->rowIndex, (string) $value);
            $colIndex++;
        }
        $this->rowIndex++;
        return $this;
    }

    /**
     * Append multiple rows at once.
     */
    public function addRows(array $records): self
    {
        foreach ($records as $record) {
            $this->addRow($record);
        }
        return $this;
    }

    /**
     * Save to file and return the path.
     */
    public function save(string $filename): string
    {
        $dir = base_path('runtime/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $filename;
        $writer = new Xlsx($this->spreadsheet);

        // Auto-size columns
        foreach (range('A', chr(64 + count($this->columns))) as $col) {
            $this->sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer->save($path);
        return $path;
    }

    /**
     * Static helper: build and save in one call.
     *
     * @return string File path
     */
    public static function export(
        string $title,
        array $columns,
        array $data,
        array $labels = []
    ): string {
        $exporter = new self($title);
        $exporter->setColumns($columns, $labels);
        $exporter->addRows($data);
        return $exporter->save(uniqid('export_') . '.xlsx');
    }
}
