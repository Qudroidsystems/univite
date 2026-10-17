<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A small, dependency-free Excel (.xlsx) writer and reader: enough for score sheets and similar tables.
 * Writing: one data sheet (bold, shaded header row, frozen, with column widths and optional number limits per column)
 * and an optional notes sheet. The data sheet can open with a letterhead (the institution's logo, name, address and the
 * document's title) and can lock some columns (the sheet is protected; the other columns stay editable). The header row
 * is saved as the sheet's print titles, so it repeats on every printed page and the reader knows where the table starts.
 * Reading: the cell values of the first sheet from its header row on, as rows of strings and numbers.
 */
class Xlsx
{
    /**
     * @param  array<int, array<int, string|int|float|null>>  $rows  the first row is the header
     * @param  array{widths?: array<int, int>, limits?: array<int, array{0: float, 1: float}>, notes?: string[], notes_title?: string, notes_sheet?: string, letterhead?: array{lines: array<int, array{0: string, 1: string}>, logo?: ?string, logo_ext?: ?string}, locked?: int[], freeze?: int}  $opts
     *                                                                 limits: column index => [min, max] (Excel refuses other numbers)
     *                                                                 letterhead: lines of [text, 'name'|'line'|'title'] above the table, and the logo's bytes
     *                                                                 locked: column indexes that cannot be changed (header and letterhead are always locked)
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
        $head = $opts['letterhead'] ?? null;
        $lines = array_values(array_filter($head['lines'] ?? [], fn ($l) => trim((string) $l[0]) !== ''));
        $offset = $lines ? count($lines) + 1 : 0;          // the letterhead, then a blank row
        $logo = $lines && ! empty($head['logo']) ? $head['logo'] : null;
        $logoExt = $logo ? (strtolower((string) ($head['logo_ext'] ?? 'png')) === 'png' ? 'png' : 'jpeg') : null;
        if ($logo && @getimagesizefromstring($logo) === false) {
            $logo = null;
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . ($logo ? '<Default Extension="' . $logoExt . '" ContentType="image/' . $logoExt . '"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
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
            . '</sheets><definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\'' . str_replace("'", "''", self::esc($sheetName)) . '\'!$' . ($offset + 1) . ':$' . ($offset + (int) ($opts['header_rows'] ?? 1)) . '</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(fn ($i) => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, count($sheets))))
            . '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Styles: 0 normal, 1 bold header on a light fill with a border, 2 bold title, 3 wrapped text, 4 letterhead name,
        // 5 letterhead line, 6 letterhead title, 7 an editable cell in a protected sheet, 8 a section heading row,
        // 9 a small grey note row, 10 a centred header cell (headers of more than one row), 11 a bordered cell with wrapped text, 12 a number with two decimals.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="6"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="13"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="15"/><color rgb="FF' . self::hex($opts['color'] ?? null) . '"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF595959"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF' . self::hex($opts['color'] ?? null) . '"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF1F6"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13"><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyProtection="1"><protection locked="0"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheetXml($rows, $opts['widths'] ?? [], $opts['limits'] ?? [], true, false, $lines, $logo !== null, $opts['locked'] ?? null, (int) ($opts['freeze'] ?? 2),
            (int) ($opts['header_rows'] ?? 1), $opts['merges'] ?? [], $opts['row_styles'] ?? [], $opts['wrap'] ?? [], $opts['decimals'] ?? []));
        if ($logo) {
            [$w, $h] = getimagesizefromstring($logo);
            $tall = 20 * min(count($lines), 4) + 6;              // about the height of the letterhead rows, in pixels
            $cy = $tall * 9525;
            $cx = (int) round($cy * $w / max(1, $h));
            $zip->addFromString('xl/media/image1.' . $logoExt, $logo);
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.' . $logoExt . '"/></Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>76200</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>38100</xdr:rowOff></xdr:from><xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
        }
        if ($notes) {
            $noteRows = array_merge([[$opts['notes_title'] ?? 'How to fill in this sheet']], array_map(fn ($n) => [$n], $notes));
            $zip->addFromString('xl/worksheets/sheet2.xml', self::sheetXml($noteRows, [0 => 100], [], false, true));
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $lines  letterhead lines written above the table
     * @param  int[]|null  $locked  column indexes that cannot be changed; null leaves the sheet unprotected
     */
    /**
     * @param  array<int, array{0: int, 1: int, 2: int, 3: int}>  $cellMerges  [first row, first column, last row, last column] of the table (row 0 = header)
     * @param  array<int, string>  $rowStyles  table row => 'section' | 'footer'
     * @param  int[]  $wrap  columns whose text wraps
     * @param  int[]  $decimals  columns whose numbers show two decimals (GPA)
     */
    private static function sheetXml(array $rows, array $widths, array $limits, bool $table, bool $notes = false, array $lines = [], bool $logo = false, ?array $locked = null, int $freeze = 2,
        int $headerRows = 1, array $cellMerges = [], array $rowStyles = [], array $wrap = [], array $decimals = []): string
    {
        $count = count($rows);
        $offset = $lines ? count($lines) + 1 : 0;
        $cols = max(1, ...array_map('count', $rows ?: [[null]]));
        $editable = fn (int $c) => $locked !== null && ! in_array($c, $locked, true);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($table) {
            $xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';   // print one page wide (pageSetup fitToWidth)
            $first = self::col($freeze) . ($offset + $headerRows + 1);
            $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane xSplit="' . $freeze . '" ySplit="' . ($offset + $headerRows) . '" topLeftCell="' . $first . '" activePane="bottomRight" state="frozen"/><selection pane="bottomRight" activeCell="' . $first . '" sqref="' . $first . '"/></sheetView></sheetViews>';
        }
        if ($widths || $locked !== null) {
            $xml .= '<cols>';
            for ($c = 0; $c < max($cols, $widths ? max(array_keys($widths)) + 1 : 0); $c++) {
                $xml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . (int) ($widths[$c] ?? 12) . '" customWidth="1"' . ($editable($c) ? ' style="7"' : '') . '/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        // The letterhead: centred lines across the table (beside the logo, when there is one).
        $from = $logo ? 1 : 0;
        $merges = [];
        foreach ($lines as $i => [$text, $kind]) {
            $style = ['name' => 4, 'title' => 6][$kind] ?? 5;
            $ref = self::col($from) . ($i + 1);
            $xml .= '<row r="' . ($i + 1) . '" ht="' . ($kind === 'name' ? 24 : 18) . '" customHeight="1"><c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $text) . '</t></is></c></row>';
            if ($cols - 1 > $from) {
                $merges[] = $ref . ':' . self::col($cols - 1) . ($i + 1);
            }
        }
        if ($lines) {
            // The blank row under the letterhead, without the borders an editable column would give it.
            $xml .= '<row r="' . $offset . '">' . implode('', array_map(fn ($c) => '<c r="' . self::col($c) . $offset . '" s="5"/>', range(0, $cols - 1))) . '</row>';
        }
        foreach ($cellMerges as [$r1, $c1, $r2, $c2]) {
            $merges[] = self::col($c1) . ($r1 + 1 + $offset) . ':' . self::col($c2) . ($r2 + 1 + $offset);
        }
        foreach (array_values($rows) as $r => $row) {
            $n = $r + 1 + $offset;
            $rowStyle = ['section' => 8, 'footer' => 9][$rowStyles[$r] ?? ''] ?? null;
            $header = $table && $r < $headerRows;
            $xml .= '<row r="' . $n . '"' . ($rowStyle === 8 ? ' ht="20" customHeight="1"' : '') . '>';
            if ($header && $headerRows > 1 || $rowStyle !== null) {
                $row = array_pad(array_values($row), $cols, null);   // merged header and section cells keep their fill and border
            }
            foreach (array_values($row) as $c => $v) {
                $ref = self::col($c) . $n;
                $style = $notes ? ($r === 0 ? 2 : 3) : ($header ? ($headerRows > 1 ? 10 : 1) : ($rowStyle ?? ($editable($c) ? 7 : (in_array($c, $wrap, true) ? 11 : (in_array($c, $decimals, true) && is_float($v) ? 12 : 0)))));
                if ($v === null || $v === '') {
                    if ($header && $headerRows > 1 || $rowStyle !== null) {
                        $xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                    }
                    continue;
                }
                $xml .= is_int($v) || is_float($v)
                    ? '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>'
                    : '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($locked !== null) {
            // No password: this guards against changing a matric number by mistake, not against a determined user.
            $xml .= '<sheetProtection sheet="1" objects="1" scenarios="1" formatColumns="0" formatRows="0" selectLockedCells="0" selectUnlockedCells="0"/>';
        }
        if ($merges) {
            $xml .= '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn ($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>';
        }
        if ($limits && $count > 1) {
            $last = max($count, 2) + $offset + 200;   // room for students added later
            $xml .= '<dataValidations count="' . count($limits) . '">';
            foreach ($limits as $c => [$min, $max]) {
                $range = self::col($c) . ($offset + 2) . ':' . self::col($c) . $last;
                $xml .= '<dataValidation type="decimal" allowBlank="1" showErrorMessage="1" errorTitle="Mark out of range" error="Enter a number from ' . $min . ' to ' . $max . '." sqref="' . $range . '">'
                    . '<formula1>' . $min . '</formula1><formula2>' . $max . '</formula2></dataValidation>';
            }
            $xml .= '</dataValidations>';
        }
        if ($table) {
            $xml .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>';
        }
        if ($logo) {
            $xml .= '<drawing r:id="rId1"/>';
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
        $headerRow = 1;
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = self::xml($wb);
            $first = $w->sheets->sheet[0] ?? null;
            $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;
            // A letterhead above the table: the header row is the first sheet's print titles row.
            foreach ($w->definedNames->definedName ?? [] as $dn) {
                if ((string) $dn['name'] === '_xlnm.Print_Titles' && (string) $dn['localSheetId'] === '0' && preg_match('/\$(\d+):\$\d+/', (string) $dn, $m)) {
                    $headerRow = (int) $m[1];
                }
            }
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
            if (isset($row['r']) && (int) $row['r'] < $headerRow) {
                continue;
            }
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

    /** "#405189" => "405189" (the default blue when the colour is not a six-digit hex). */
    private static function hex(?string $color): string
    {
        $h = strtoupper(ltrim((string) $color, '#'));

        return preg_match('/^[0-9A-F]{6}$/', $h) ? $h : '405189';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
