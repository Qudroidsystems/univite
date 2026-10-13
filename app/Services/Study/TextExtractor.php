<?php

namespace App\Services\Study;

use ZipArchive;

/**
 * Plain text from the files students study from: text and Markdown, Word (.docx), PowerPoint (.pptx) and PDF.
 * PDF text is read from the file's text operators, which covers most notes and slides exported from Word or
 * PowerPoint; scanned PDFs and photos have no text layer, so StudyCompanion asks the AI service to read those.
 */
class TextExtractor
{
    public const TYPES = ['pdf', 'docx', 'pptx', 'txt', 'md', 'jpg', 'jpeg', 'png', 'webp'];

    public function extract(string $path, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'txt', 'md' => (string) file_get_contents($path),
            'docx' => $this->docx($path),
            'pptx' => $this->pptx($path),
            'pdf' => $this->pdf((string) file_get_contents($path)),
            default => '',
        };

        return $this->tidy($text);
    }

    /** Whether the text is worth using: enough real words, not font gibberish. */
    public function usable(string $text, int $pages = 1): bool
    {
        $words = preg_match_all('/\b[a-zA-Z]{3,}\b/', $text);

        return $words >= max(40, 25 * min($pages, 20));
    }

    public function pdfPages(string $data): int
    {
        return max(1, preg_match_all('#/Type\s*/Page[^s]#', $data));
    }

    public function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t", "\f"], ["\n", "\n", ' ', "\n"], $text);
        $text = preg_replace('/[^\P{C}\n]/u', '', mb_convert_encoding($text, 'UTF-8', 'UTF-8')) ?? $text;
        $text = preg_replace('/[ \x{00A0}]{2,}/u', ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function docx(string $path): string
    {
        $xml = $this->zipEntry($path, 'word/document.xml');
        if ($xml === null) {
            return '';
        }
        $xml = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br[^>]*/>#'], ["\n", ' ', "\n"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function pptx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int) $m[1]] = $zip->getFromIndex($i);
            }
        }
        $zip->close();
        ksort($slides);
        $out = [];
        foreach ($slides as $n => $xml) {
            $xml = preg_replace(['#</a:p>#'], ["\n"], $xml);
            $out[] = "Slide {$n}\n" . trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }

        return implode("\n\n", $out);
    }

    private function zipEntry(string $path, string $entry): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName($entry);
        $zip->close();

        return $xml === false ? null : $xml;
    }

    /** Text shown by a PDF's BT ... ET blocks (Tj, TJ, ' and " operators), with Flate-compressed streams opened. */
    public function pdf(string $data): string
    {
        $out = [];
        if (! preg_match_all('#<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream#s', $data, $streams, PREG_SET_ORDER)) {
            return '';
        }
        foreach ($streams as [, $dict, $body]) {
            if (preg_match('#/Subtype\s*/(Image|XML)|/Type\s*/(XRef|ObjStm|Metadata)#', $dict)) {
                continue;
            }
            if (str_contains($dict, '/FlateDecode')) {
                $body = @gzuncompress($body) ?: (@gzinflate(substr($body, 2)) ?: '');
            } elseif (preg_match('#/Filter#', $dict)) {
                continue;   // other encodings (DCT images, LZW ...) carry no readable text here
            }
            if ($body === '' || ! str_contains($body, 'BT')) {
                continue;
            }
            $out[] = $this->pdfText($body);
        }

        return implode("\n", array_filter($out));
    }

    private function pdfText(string $content): string
    {
        $text = '';
        preg_match_all('#BT(.*?)ET#s', $content, $blocks);
        foreach ($blocks[1] as $block) {
            $line = '';
            preg_match_all('#(\[(?:[^\]\\\\]|\\\\.)*\]\s*TJ|\((?:[^()\\\\]|\\\\.|\((?:[^()\\\\]|\\\\.)*\))*\)\s*(?:Tj|\'|")|T\*|-?[\d.]+\s+-?[\d.]+\s+T[dD]|<[0-9A-Fa-f\s]+>\s*Tj)#s', $block, $ops);
            foreach ($ops[0] as $op) {
                if ($op === 'T*' || preg_match('#T[dD]$#', $op)) {
                    if (preg_match('#^-?[\d.]+\s+(-?[\d.]+)\s+T[dD]$#', $op, $m) && (float) $m[1] === 0.0) {
                        continue;   // a move along the same line
                    }
                    $line .= "\n";
                    continue;
                }
                if (str_ends_with($op, 'TJ')) {
                    preg_match_all('#\((?:[^()\\\\]|\\\\.)*\)|-?\d+(?:\.\d+)?#s', substr($op, 1, strrpos($op, ']') - 1), $parts);
                    foreach ($parts[0] as $p) {
                        if ($p[0] === '(') {
                            $line .= $this->pdfString(substr($p, 1, -1));
                        } elseif ((float) $p < -200) {
                            $line .= ' ';   // a wide kerning gap is a word space
                        }
                    }
                    continue;
                }
                if ($op[0] === '<') {
                    $hex = preg_replace('/\s+/', '', substr($op, 1, strpos($op, '>') - 1));
                    $bytes = @hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex) ?: '';
                    $line .= preg_match('/^(\x00[\x20-\x7e])+$/', $bytes) ? str_replace("\x00", '', $bytes) : '';
                    continue;
                }
                $line .= $this->pdfString(substr($op, 1, strrpos($op, ')') - 1));
            }
            $text .= $line . "\n";
        }

        return $text;
    }

    private function pdfString(string $s): string
    {
        $s = preg_replace_callback('#\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)#', function ($m) {
            $c = $m[1];

            return match (true) {
                $c === 'n', $c === 'r' => "\n",
                $c === 't' => ' ',
                $c === 'b', $c === 'f', str_starts_with($c, "\n"), str_starts_with($c, "\r") => '',
                ctype_digit($c) => chr(octdec($c) & 0xFF),
                default => $c,
            };
        }, $s);

        // Most PDFs from office tools use WinAnsi encoding for plain fonts.
        return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
