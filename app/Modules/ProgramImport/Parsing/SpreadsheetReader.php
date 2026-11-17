<?php

namespace App\Modules\ProgramImport\Parsing;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Streams rows from CSV/TXT (fgetcsv) or XLSX (chunked PhpSpreadsheet reads, read-data-only; external entities are never loaded).
 * Rows are arrays keyed by header, with their 1-based spreadsheet row number.
 */
class SpreadsheetReader
{
    public const CHUNK = 500;

    /** @return list<string> sheet names; CSV has a single sheet named "data". */
    public function sheetNames(string $path, string $ext): array
    {
        if ($ext !== 'xlsx') {
            return ['data'];
        }
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);

        return array_values($reader->listWorksheetNames($path));
    }

    /** @return \Generator<int, array{row: int, cells: array<string,mixed>}> */
    public function rows(string $path, string $ext, string $sheet = 'data', int $maxRows = 5000, int $maxColumns = 80): \Generator
    {
        $count = 0;
        foreach ($ext === 'xlsx' ? $this->xlsx($path, $sheet, $maxColumns) : $this->csv($path, $maxColumns) as $item) {
            if (++$count > $maxRows) {
                throw new ImportLimitException("The file has more than $maxRows rows.");
            }
            yield $item;
        }
    }

    private function csv(string $path, int $maxColumns): \Generator
    {
        $h = fopen($path, 'rb');
        if (! $h) {
            throw new \RuntimeException('Cannot open the file.');
        }
        $sample = (string) fread($h, 8192);
        rewind($h);
        $utf8 = mb_check_encoding($sample, 'UTF-8');
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            fread($h, 3);
            $sample = substr($sample, 3);
        }
        $first = strtok($sample, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = array_key_first($counts);

        $headers = null;
        $n = 0;
        while (($line = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            $n++;
            if (! array_filter($line, fn ($c) => $c !== null && trim((string) $c) !== '')) {
                continue; // blank row
            }
            if (! $utf8) {
                $line = array_map(fn ($c) => $c === null ? null : mb_convert_encoding($c, 'UTF-8', 'Windows-1252'), $line);
            }
            if ($headers === null) {
                $headers = $this->headers($line, $maxColumns);

                continue;
            }
            yield ['row' => $n, 'cells' => $this->combine($headers, $line)];
        }
        fclose($h);
    }

    private function xlsx(string $path, string $sheet, int $maxColumns): \Generator
    {
        $filter = new class implements IReadFilter
        {
            public int $start = 1;

            public int $end = 1;

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->start && $row < $this->end;
            }
        };
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheet]);
        $reader->setReadFilter($filter);

        $headers = null;
        for ($start = 1; ; $start += self::CHUNK) {
            // Header cells are always needed for the column names; later chunks read their own rows only.
            $filter->start = $start;
            $filter->end = $start + self::CHUNK;
            $book = $reader->load($path);
            $ws = $book->getActiveSheet();
            $highest = $ws->getHighestDataRow();
            foreach ($ws->toArray(null, false, false, false) as $i => $line) {
                $rowNo = $i + 1;
                if ($rowNo < $start || $rowNo >= $start + self::CHUNK || ! array_filter($line, fn ($c) => $c !== null && trim((string) $c) !== '')) {
                    continue;
                }
                if ($headers === null) {
                    $headers = $this->headers($line, $maxColumns);

                    continue;
                }
                yield ['row' => $rowNo, 'cells' => $this->combine($headers, $line)];
            }
            $book->disconnectWorksheets();
            if ($highest < $start + self::CHUNK) {
                break;
            }
        }
    }

    private function headers(array $line, int $maxColumns): array
    {
        $headers = [];
        foreach ($line as $i => $h) {
            $h = trim((string) $h);
            $headers[$i] = $h === '' ? null : $h;
        }
        if (count(array_filter($headers)) > $maxColumns) {
            throw new ImportLimitException("The file has more than $maxColumns columns.");
        }

        return $headers;
    }

    private function combine(array $headers, array $line): array
    {
        $cells = [];
        foreach ($headers as $i => $name) {
            if ($name === null) {
                continue;
            }
            $v = $line[$i] ?? null;
            $cells[$name] = is_string($v) ? trim($v) : $v;
        }

        return $cells;
    }
}
