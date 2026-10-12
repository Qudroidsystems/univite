<?php

namespace App\Data\Api;

use App\Models\ResultSheet;
use App\Services\ResultService;

/**
 * A course score sheet for the lecturer's app: the assessments (or plain CA / exam), one row per student with their marks,
 * where the sheet is in the approval chain, and whether this person may still change it.
 */
final readonly class ApiScoreSheet
{
    public function __construct(public array $data)
    {
    }

    public static function from(ResultSheet $sheet, bool $canEnter, int $missing, bool $withRows = true): self
    {
        $sheet->loadMissing('course', 'session', 'semesterType', 'stage', 'scheme.items');
        [$caMax, $examMax] = ResultService::limits($sheet);
        $scheme = $sheet->scheme;
        $columns = $scheme
            ? $scheme->items->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'component' => $i->component, 'max' => (float) $i->max_score])->values()->all()
            : [['id' => null, 'code' => 'CA', 'name' => 'Continuous assessment', 'component' => 'ca', 'max' => $caMax],
                ['id' => null, 'code' => 'EXAM', 'name' => 'Examination', 'component' => 'exam', 'max' => $examMax]];

        $data = [
            'id' => $sheet->id,
            'course' => ['id' => $sheet->course->id, 'code' => $sheet->course->code, 'title' => $sheet->course->title, 'units' => (int) $sheet->course->credit_units],
            'session' => $sheet->session?->name,
            'semester' => $sheet->semesterType?->name,
            'status' => $sheet->status,
            'status_label' => ResultSheet::STATUSES[$sheet->status] ?? $sheet->status,
            'stage' => $sheet->stage?->name,
            'editable' => $sheet->isEditable() && $canEnter,
            'mode' => $scheme ? 'items' : 'ca_exam',
            'scheme' => $scheme ? ['id' => $scheme->id, 'name' => $scheme->name, 'summary' => $scheme->summary()] : null,
            'ca_max' => $caMax,
            'exam_max' => $examMax,
            'assessments' => $columns,
            'students' => $sheet->results_count ?? $sheet->results()->count(),
            'missing' => $missing,
            'submitted_at' => $sheet->submitted_at?->toIso8601String(),
            'published_at' => $sheet->published_at?->toIso8601String(),
        ];

        if ($withRows) {
            $sheet->loadMissing('results.student', 'results.assessmentScores', 'events.user');
            $data['rows'] = $sheet->results->sortBy(fn ($r) => $r->student->last_name . ' ' . $r->student->first_name)->map(function ($r) use ($scheme) {
                $have = $r->assessmentScores->keyBy('assessment_item_id');

                return [
                    'student_id' => $r->student_id,
                    'matric_number' => $r->student->matric_number,
                    'name' => $r->student->full_name,
                    'marks' => $scheme
                        ? $scheme->items->mapWithKeys(fn ($i) => [(string) $i->id => $have->has($i->id) ? (float) $have[$i->id]->score : null])->all()
                        : ['CA' => $r->ca, 'EXAM' => $r->exam],
                    'ca' => $r->ca, 'exam' => $r->exam, 'total' => $r->total, 'grade' => $r->grade,
                    'passed' => $r->is_pass,
                ];
            })->values()->all();
            $data['history'] = $sheet->events->take(20)->map(fn ($e) => [
                'at' => $e->created_at?->toIso8601String(), 'action' => $e->action, 'stage' => $e->stage, 'by' => $e->user?->name, 'note' => $e->note,
            ])->values()->all();
        }

        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
