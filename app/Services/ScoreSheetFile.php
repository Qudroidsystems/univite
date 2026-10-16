<?php

namespace App\Services;

use App\Models\ResultSheet;
use App\Support\Xlsx;

/**
 * A score sheet as a file a lecturer fills in offline and uploads again: Matric Number, Name, one column per
 * assessment (detailed) or only the CA and exam totals (totals only), then the total and grade, with the marks already
 * entered. Excel files carry each column's limit, so Excel itself
 * refuses a mark above the maximum, and a second sheet explains how to fill it in. Read back by ResultService::import().
 */
class ScoreSheetFile
{
    public const TYPES = ['detailed' => 'Detailed (every assessment)', 'total' => 'Totals only (CA, exam, total)'];

    /**
     * The sheet as rows (header first) and the limits of the columns a lecturer may fill in. "detailed" has a column per
     * assessment, then the CA and exam totals; "total" has only the CA and exam totals. Both end with the total and
     * grade (worked out by the portal; an upload ignores them).
     *
     * @return array{0: array<int, array>, 1: array<int, array{0: float, 1: float}>}
     */
    public function table(ResultSheet $sheet, string $type = 'detailed'): array
    {
        $sheet->loadMissing('course', 'scheme.items');
        $rows = $sheet->results()->with('student', 'assessmentScores')->get()->sortBy(fn ($r) => $r->student->last_name . ' ' . $r->student->first_name);
        $num = fn ($v) => $v === null ? null : (float) $v + 0;
        [$caMax, $examMax] = ResultService::limits($sheet);
        $items = ($type === 'detailed' && $sheet->scheme) ? $sheet->scheme->items->values() : collect();

        $head = ['Matric Number', 'Name'];
        $limits = [];
        foreach ($items as $k => $i) {
            $head[] = "{$i->code} ({$i->maxLabel()})";
            $limits[$k + 2] = [0, (float) $i->max_score];
        }
        $at = count($head);
        $head[] = ($sheet->scheme ? 'CA total' : 'CA') . ' (' . ($caMax + 0) . ')';
        $head[] = ($sheet->scheme ? 'Exam total' : 'Exam') . ' (' . ($examMax + 0) . ')';
        $limits[$at] = [0, (float) $caMax];
        $limits[$at + 1] = [0, (float) $examMax];
        $limits[$at + 2] = [0, (float) ($caMax + $examMax)];
        $head[] = 'Total (' . ($caMax + $examMax + 0) . ')';
        $head[] = 'Grade';

        $table = [$head];
        foreach ($rows as $r) {
            $have = $r->assessmentScores->keyBy('assessment_item_id');
            $table[] = array_merge(
                [$r->student->matric_number, $r->student->full_name],
                $items->map(fn ($i) => $have->has($i->id) ? $num($have[$i->id]->score) : null)->all(),
                [$num($r->ca), $num($r->exam), $num($r->total), $r->grade],
            );
        }

        return [$table, $limits];
    }

    public function filename(ResultSheet $sheet, string $ext, string $type = 'detailed'): string
    {
        $sheet->loadMissing('course', 'session', 'semesterType');

        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $sheet->course->code . '_' . ($sheet->session?->name ?? '') . '_' . ($sheet->semesterType?->name ?? '') . ($type === 'total' ? '_totals' : '_scores')) . '.' . $ext;
    }

    public function xlsx(ResultSheet $sheet, string $type = 'detailed'): string
    {
        [$table, $limits] = $this->table($sheet, $type);
        $sheet->loadMissing('course', 'session', 'semesterType', 'scheme.items');
        $widths = [0 => 20, 1 => 34] + array_fill(2, max(1, count($table[0]) - 2), 13);
        $detailed = $type !== 'total' && $sheet->scheme;
        $notes = [
            $sheet->course->code . ' ' . $sheet->course->title . ' · ' . ($sheet->session?->name ?? '') . ' ' . ($sheet->semesterType?->name ?? ''),
            $detailed
                ? 'Enter each assessment mark. The number in brackets is the most it is out of; Excel refuses anything outside 0 to that number. The CA and exam totals are added up by the portal; you may instead leave a part\'s assessments empty and type its total.'
                : 'Enter each student\'s CA total and exam total. The number in brackets is the most each is out of; Excel refuses anything outside 0 to that number.',
            'Total is worked out by the portal from the CA and exam. To give a student only an overall total, leave the CA, exam' . ($detailed ? ' and assessment' : '') . ' cells empty and type the Total; the assessments are then cleared. Grade is always worked out.',
            'Leave a cell empty to keep it as it is on the portal. Type 0 for a student who was absent.',
            'Do not change the Matric Number column or the column headings. Rows can be in any order.',
            'Save the file and upload it on the score sheet (in the app: the menu on the sheet, Upload Excel; on the portal: Upload).',
            $sheet->scheme ? 'Assessments: ' . $sheet->scheme->summary() . '.' : 'This sheet has one CA and one exam score per student.',
        ];

        return Xlsx::write($table, $sheet->course->code, ['widths' => $widths, 'limits' => $limits, 'notes' => $notes, 'notes_title' => 'How to fill in this score sheet']);
    }

    public function csv(ResultSheet $sheet, string $type = 'detailed'): string
    {
        [$table] = $this->table($sheet, $type);
        $cell = fn ($v) => $v === null ? '' : (is_string($v) ? '"' . str_replace('"', '""', $v) . '"' : (string) $v);

        return "\xEF\xBB\xBF" . implode("\n", array_map(fn ($row) => implode(',', array_map($cell, $row)), $table)) . "\n";
    }
}
