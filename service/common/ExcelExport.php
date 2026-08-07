<?php
namespace Common;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

    public function addRow(array $record): self
    {
        $colIndex = 'A';
        foreach ($this->columns as $col) {
            $value = $record[$col] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value = (string) $value;
            // 防公式注入：以 = + - @ 开头的值强制存为文本，避免被 Excel 当作公式执行
            if ($value !== '' && str_contains('=+-@', $value[0])) {
                $this->sheet->setCellValueExplicit($colIndex . $this->rowIndex, $value, DataType::TYPE_STRING);
            } else {
                $this->sheet->setCellValue($colIndex . $this->rowIndex, $value);
            }
            $colIndex++;
        }
        $this->rowIndex++;
        return $this;
    }

    public function addRows(array $records): self
    {
        foreach ($records as $record) {
            $this->addRow($record);
        }
        return $this;
    }

    public function save(string $filename): string
    {
        $dir = base_path('runtime/exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // basename 防止调用方传入带路径分隔符的文件名造成目录穿越
        $path = $dir . '/' . basename($filename);
        $writer = new Xlsx($this->spreadsheet);

        foreach (range('A', Coordinate::stringFromColumnIndex(count($this->columns))) as $col) {
            $this->sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer->save($path);
        return $path;
    }

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
