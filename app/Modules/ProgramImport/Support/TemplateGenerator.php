<?php

namespace App\Modules\ProgramImport\Support;

use App\Modules\ProgramImport\Registry\ImportTypeRegistry;
use App\Modules\ProgramImport\Registry\StartPointCatalog;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Downloadable templates generated from the registry: headers are the registry keys, so mapping is automatic. */
class TemplateGenerator
{
    public static function exists(string $name): bool
    {
        return in_array($name, StartPointCatalog::POINTS, true) || ImportTypeRegistry::has($name);
    }

    /** @return string path of a temp file */
    public function build(string $name, string $format): string
    {
        $isPoint = in_array($name, StartPointCatalog::POINTS, true);
        if ($isPoint && $format === 'csv') {
            throw new \InvalidArgumentException('bundle_requires_xlsx');
        }
        $sheets = $isPoint ? StartPointCatalog::sheets($name) : [$name];
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Instructions');
        $this->instructions($book->getActiveSheet(), $name, $isPoint, $sheets);
        foreach ($sheets as $type) {
            $ws = $book->createSheet();
            $ws->setTitle($type);
            $col = 1;
            foreach (ImportTypeRegistry::fields($type) as $key => $f) {
                $ws->setCellValue([$col, 1], $key);
                $ws->getStyle([$col, 1])->getFont()->setBold($f['required']);
                if ($f['type'] === 'enum' && $f['enum']) {
                    $dv = new DataValidation;
                    $dv->setType(DataValidation::TYPE_LIST)->setAllowBlank(! $f['required'])->setShowDropDown(true)->setFormula1('"'.implode(',', $f['enum']).'"');
                    for ($r = 2; $r <= 500; $r++) {
                        $ws->getCell([$col, $r])->setDataValidation(clone $dv);
                    }
                }
                $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
                $col++;
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'pit');
        if ($format === 'csv') {
            $book->setActiveSheetIndex(1);
            $w = new Csv($book);
            $w->setSheetIndex(1);
            $w->save($path);
        } else {
            $book->setActiveSheetIndex(0);
            (new Xlsx($book))->save($path);
        }

        return $path;
    }

    private function instructions($ws, string $name, bool $isPoint, array $sheets): void
    {
        $rows = [['Tujitume program import template: '.$name], ['Bold headers are required. Keep the header row as it is. Dates: YYYY-MM-DD. Amounts: digits, e.g. 50000 or KES 50,000 (never "50k").'], ['Lists: separate items with ;'], []];
        if ($isPoint) {
            $p = StartPointCatalog::get($name);
            $rows[] = [$p['summary']];
            $rows[] = ['Required sheets: '.implode(', ', $p['required']).'. Optional: '.($p['optional'] ? implode(', ', $p['optional']) : 'none')];
            $rows[] = [];
        }
        $rows[] = ['Sheet', 'Field', 'Label', 'Type', 'Required', 'Allowed values'];
        foreach ($sheets as $type) {
            foreach (ImportTypeRegistry::fields($type) as $key => $f) {
                $rows[] = [$type, $key, $f['label'], $f['type'], $f['required'] ? 'yes' : 'no', $f['enum'] ? implode(', ', $f['enum']) : ''];
            }
        }
        $ws->fromArray($rows, null, 'A1', true);
        $ws->getColumnDimension('A')->setWidth(22);
        $ws->getColumnDimension('B')->setWidth(32);
        $ws->getColumnDimension('C')->setWidth(34);
    }
}
