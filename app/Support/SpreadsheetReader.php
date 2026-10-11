<?php

namespace App\Support;

use RuntimeException;

/** Reads the rows of a CSV or Excel file as arrays of cell values, skipping blank rows. Injected where files are read. */
class SpreadsheetReader
{
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    public function read(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            if (! class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                throw new RuntimeException('Excel files are not supported on this server yet. Save the sheet as CSV and upload that.');
            }
            $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);

            return array_values(array_filter($rows, fn ($r) => collect($r)->filter(fn ($c) => $c !== null && $c !== '')->isNotEmpty()));
        }

        $rows = [];
        $h = fopen($path, 'r');
        while (($line = fgetcsv($h)) !== false) {
            if (collect($line)->filter(fn ($c) => $c !== null && trim((string) $c) !== '')->isEmpty()) {
                continue;
            }
            $rows[] = $line;
        }
        fclose($h);
        if ($rows) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);   // BOM
        }

        return $rows;
    }
}
