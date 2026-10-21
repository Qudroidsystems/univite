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
            'course' => ApiCourse::from($sheet->course)->toArray(),
            'approval_steps' => self::steps($sheet),
            'session' => $sheet->session?->name,
            'semester' => $sheet->semesterType?->name,
            'status' => $sheet->status,
            'status_label' => ResultSheet::STATUSES[$sheet->status] ?? $sheet->status,
            'stage' => $sheet->stage?->name,
            'editable' => $sheet->isEditable() && $canEnter,
            'mode' => $scheme ? 'items' : 'ca_exam',
            'scheme' => $scheme ? ['id' => $scheme->id, 'name' => $scheme->name, 'summary' => $scheme->summary()] : null,
            // Shown to the lecturer when the sheet is on plain CA / exam.
            'scheme_note' => $scheme ? null : 'No assessment scheme applies to this course, so marks are entered as one CA and one exam. Ask the exams office to assign a scheme (Academic Setup, Assessment Schemes) or to change the assessments on this sheet.',
            'ca_max' => $caMax,
            'exam_max' => $examMax,
            // how a student marked absent is graded: "incomplete" (ABS, outside the GPA) or "zero" (missing marks count 0)
            'absent_policy' => \App\Services\ResultService::absentPolicy(),
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
                    // did not sit (told apart from a 0)
                    'absent' => (bool) $r->absent,
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

    /**
     * Where the sheet is in the approval chain: each step with done / waiting / to come, and who signed it since the
     * sheet was last submitted.
     */
    public static function steps(ResultSheet $sheet): array
    {
        $chain = \App\Models\ResultStage::chain()->values();
        $since = \App\Models\ResultSheetEvent::where('result_sheet_id', $sheet->id)->where('action', 'submitted')->max('id');
        $signed = \App\Models\ResultSheetEvent::with('user')->where('result_sheet_id', $sheet->id)->where('action', 'approved')
            ->when($since, fn ($q) => $q->where('id', '>', $since))->get()->groupBy('stage');
        $at = $chain->search(fn ($s) => $s->id === $sheet->stage_id);

        return $chain->map(function ($s, $i) use ($sheet, $at, $signed) {
            $state = match (true) {
                in_array($sheet->status, ['approved', 'published'], true) => 'done',
                $sheet->status === 'submitted' && $at !== false && $i < $at => 'done',
                $sheet->status === 'submitted' && $i === $at => 'waiting',
                default => 'to_come',
            };
            $by = $signed->get($s->name)?->last();

            return ['step' => $i + 1, 'name' => $s->name, 'role' => $s->office_role, 'state' => $state,
                'signed_by' => $state === 'done' && $by ? $by->user?->name : null, 'signed_at' => $state === 'done' && $by ? $by->created_at?->toIso8601String() : null];
        })->all();
    }
}
