<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A small, dependency-free Excel (.xlsx) writer and reader: enough for score sheets and similar tables.
 * Writing: one data sheet (bold, shaded header row, frozen, with column widths and optional number limits per column)
 * and an optional notes sheet. Reading: the cell values of the first sheet, as rows of strings and numbers.
 */
class Xlsx
{
    /**
     * @param  array<int, array<int, string|int|float|null>>  $rows  the first row is the header
     * @param  array{widths?: array<int, int>, limits?: array<int, array{0: float, 1: float}>, notes?: string[], notes_title?: string, notes_sheet?: string}  $opts
     *                                                                 limits: column index => [min, max] (Excel refuses other numbers)
     */
    public static function write(array $rows, string $sheetName = 'Sheet1', array $opts = []): string
    {
        $sheetName = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $sheetName) ?: 'Sheet1', 0, 31);
        $notes = $opts['notes'] ?? [];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build the Excel file.');
        }
        $sheets = [$sheetName];
        if ($notes) {
            $sheets[] = $opts['notes_sheet'] ?? 'How to fill in';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, count($sheets))))
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>'
            . implode('', array_map(fn ($n, $i) => '<sheet name="' . self::esc($n) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>', $sheets, array_keys($sheets)))
            . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(fn ($i) => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, count($sheets))))
            . '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Styles: 0 normal, 1 bold header on a light fill with a border, 2 bold title, 3 wrapped text.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF405189"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($rows, $opts['widths'] ?? [], $opts['limits'] ?? [], true));
        if ($notes) {
            $noteRows = array_merge([[$opts['notes_title'] ?? 'How to fill in this sheet']], array_map(fn ($n) => [$n], $notes));
            $zip->addFromString('xl/worksheets/sheet2.xml', self::sheetXml($noteRows, [0 => 100], [], false, true));
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }

    private static function sheetXml(array $rows, array $widths, array $limits, bool $table, bool $notes = false): string
    {
        $count = count($rows);
        $cols = max(1, ...array_map('count', $rows ?: [[null]]));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($table) {
            $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane xSplit="2" ySplit="1" topLeftCell="C2" activePane="bottomRight" state="frozen"/><selection pane="bottomRight" activeCell="C2" sqref="C2"/></sheetView></sheetViews>';
        }
        if ($widths) {
            $xml .= '<cols>' . implode('', array_map(fn ($i, $w) => '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int) $w . '" customWidth="1"/>', array_keys($widths), $widths)) . '</cols>';
        }
        $xml .= '<sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::col($c) . ($r + 1);
                $style = $notes ? ($r === 0 ? 2 : 3) : ($r === 0 && $table ? 1 : 0);
                $xml .= is_int($v) || is_float($v)
                    ? '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>'
                    : '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($limits && $count > 1) {
            $last = max($count, 2) + 200;   // room for students added later
            $xml .= '<dataValidations count="' . count($limits) . '">';
            foreach ($limits as $c => [$min, $max]) {
                $range = self::col($c) . '2:' . self::col($c) . $last;
                $xml .= '<dataValidation type="decimal" allowBlank="1" showErrorMessage="1" errorTitle="Mark out of range" error="Enter a number from ' . $min . ' to ' . $max . '." sqref="' . $range . '">'
                    . '<formula1>' . $min . '</formula1><formula2>' . $max . '</formula2></dataValidation>';
            }
            $xml .= '</dataValidations>';
        }

        return $xml . '</worksheet>';
    }

    /** The first sheet's cells as rows (strings, or numbers for numeric cells), blank rows dropped. */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That file is not a valid Excel (.xlsx) file.');
        }
        $strings = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = self::xml($ss);
            foreach ($doc->si as $si) {
                $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $first = $w->sheets->sheet[0] ?? null;
            $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;
            foreach (self::xml($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $t = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                }
            }
        }
        $sheet = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('The Excel file has no sheet to read.');
        }

        $rows = [];
        foreach (self::xml($sheet)->sheetData->row as $row) {
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $idx = isset($c['r']) ? self::colIndex((string) $c['r']) : $next;
                $type = (string) $c['t'];
                $v = match ($type) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => isset($c->is->t) ? (string) $c->is->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($c->is->r, false))),
                    'b' => (string) $c->v === '1' ? 'TRUE' : 'FALSE',
                    'str', 'e' => (string) $c->v,
                    default => isset($c->v) ? (is_numeric((string) $c->v) ? (float) $c->v + 0 : (string) $c->v) : null,
                };
                $cells[$idx] = $v;
                $next = $idx + 1;
            }
            if (! $cells) {
                continue;
            }
            $line = array_fill(0, max(array_keys($cells)) + 1, null);
            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }
            if (collect($line)->filter(fn ($x) => $x !== null && trim((string) $x) !== '')->isNotEmpty()) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    private static function xml(string $s): \SimpleXMLElement
    {
        $doc = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET);
        if ($doc === false) {
            throw new RuntimeException('The Excel file could not be read.');
        }

        return $doc;
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
