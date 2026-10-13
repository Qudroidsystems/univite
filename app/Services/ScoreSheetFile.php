<?php

namespace App\Services;

use App\Models\ResultSheet;
use App\Support\Xlsx;

/**
 * A score sheet as a file a lecturer fills in offline and uploads again: Matric Number, Name and one column per
 * assessment (or CA and Exam), with the marks already entered. Excel files carry each column's limit, so Excel itself
 * refuses a mark above the maximum, and a second sheet explains how to fill it in. Read back by ResultService::import().
 */
class ScoreSheetFile
{
    /** @return array{0: array<int, array>, 1: array<int, array{0: float, 1: float}>} rows (header first) and column limits */
    public function table(ResultSheet $sheet): array
    {
        $sheet->loadMissing('course', 'scheme.items');
        $rows = $sheet->results()->with('student', 'assessmentScores')->get()->sortBy(fn ($r) => $r->student->last_name . ' ' . $r->student->first_name);
        $num = fn ($v) => $v === null ? null : (float) $v + 0;
        $limits = [];
        if ($scheme = $sheet->scheme) {
            $head = ['Matric Number', 'Name'];
            foreach ($scheme->items->values() as $k => $i) {
                $head[] = "{$i->code} ({$i->maxLabel()})";
                $limits[$k + 2] = [0, (float) $i->max_score];
            }
            $table = [$head];
            foreach ($rows as $r) {
                $have = $r->assessmentScores->keyBy('assessment_item_id');
                $table[] = array_merge([$r->student->matric_number, $r->student->full_name], $scheme->items->map(fn ($i) => $have->has($i->id) ? $num($have[$i->id]->score) : null)->all());
            }
        } else {
            [$caMax, $examMax] = ResultService::limits($sheet);
            $table = [['Matric Number', 'Name', 'CA (' . ($caMax + 0) . ')', 'Exam (' . ($examMax + 0) . ')']];
            $limits = [2 => [0, (float) $caMax], 3 => [0, (float) $examMax]];
            foreach ($rows as $r) {
                $table[] = [$r->student->matric_number, $r->student->full_name, $num($r->ca), $num($r->exam)];
            }
        }

        return [$table, $limits];
    }

    public function filename(ResultSheet $sheet, string $ext): string
    {
        $sheet->loadMissing('course', 'session', 'semesterType');

        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $sheet->course->code . '_' . ($sheet->session?->name ?? '') . '_' . ($sheet->semesterType?->name ?? '') . '_scores') . '.' . $ext;
    }

    public function xlsx(ResultSheet $sheet): string
    {
        [$table, $limits] = $this->table($sheet);
        $sheet->loadMissing('course', 'session', 'semesterType', 'scheme.items');
        $widths = [0 => 20, 1 => 34] + array_fill(2, max(1, count($table[0]) - 2), 13);
        $notes = [
            $sheet->course->code . ' ' . $sheet->course->title . ' · ' . ($sheet->session?->name ?? '') . ' ' . ($sheet->semesterType?->name ?? ''),
            'Enter each student\'s mark in the columns after Name. The number in brackets is the most the assessment is out of; Excel refuses anything outside 0 to that number.',
            'Leave a cell empty to keep it as it is on the portal. Type 0 for a student who was absent.',
            'Do not change the Matric Number column or the column headings. Rows can be in any order.',
            'Save the file and upload it on the score sheet (in the app: the menu on the sheet, Upload Excel; on the portal: Upload).',
            $sheet->scheme ? 'Assessments: ' . $sheet->scheme->summary() . '. CA and exam are added up for you.' : 'This sheet has one CA and one exam score per student.',
        ];

        return Xlsx::write($table, $sheet->course->code, ['widths' => $widths, 'limits' => $limits, 'notes' => $notes, 'notes_title' => 'How to fill in this score sheet']);
    }

    public function csv(ResultSheet $sheet): string
    {
        [$table] = $this->table($sheet);
        $cell = fn ($v) => $v === null ? '' : (is_string($v) ? '"' . str_replace('"', '""', $v) . '"' : (string) $v);

        return "\xEF\xBB\xBF" . implode("\n", array_map(fn ($row) => implode(',', array_map($cell, $row)), $table)) . "\n";
    }
}
