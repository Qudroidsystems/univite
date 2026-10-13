<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiScoreSheet;
use App\Data\Results\Score;
use App\Models\Course;
use App\Models\ResultSheet;
use App\Services\RegistrationService;
use App\Services\ResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A lecturer's courses and score sheets: open a sheet, enter marks per assessment, submit for approval.
 * The same rules as the portal apply (ResultService): only assigned lecturers (or "Enter results for any course") may enter marks.
 */
class TeachingController extends ApiController
{
    public function __construct(private ResultService $results, private RegistrationService $registration, private \App\Services\AssessmentService $assessments)
    {
    }

    /**
     * The assessments a course is marked on this semester (Test 1 /10, Assignment /20, Exam /70 ...), so the lecturer's
     * course list shows them before the sheet is opened. An opened sheet keeps the scheme it started with.
     */
    private function assessmentsFor(Course $course, ?ResultSheet $sheet, ?int $sessionId): array
    {
        if ($sheet) {
            $sheet->loadMissing('scheme.items');
            [$caMax, $examMax] = ResultService::limits($sheet);
            $scheme = $sheet->scheme;
        } else {
            [$scheme] = $this->assessments->resolve($course, $sessionId);
            [$caMax, $examMax] = $scheme ? [$scheme->caMax(), $scheme->examMax()] : [ResultService::caMax(), ResultService::examMax()];
        }

        return [
            'scheme' => $scheme ? ['id' => $scheme->id, 'name' => $scheme->name, 'summary' => $scheme->summary()] : null,
            'assessments' => $scheme
                ? $scheme->items->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'component' => $i->component, 'max' => (float) $i->max_score])->values()->all()
                : [['id' => null, 'code' => 'CA', 'name' => 'Continuous assessment', 'component' => 'ca', 'max' => (float) $caMax],
                    ['id' => null, 'code' => 'EXAM', 'name' => 'Examination', 'component' => 'exam', 'max' => (float) $examMax]],
        ];
    }

    private function staff(Request $request): void
    {
        abort_if($request->user()->student, 403, 'This is only for staff.');
    }

    private function mayView(Request $request, ResultSheet $sheet): bool
    {
        return $this->results->canEnter($request->user(), $sheet) || $request->user()->can('View result sheets');
    }

    private function sheetData(Request $request, ResultSheet $sheet, bool $rows = true): array
    {
        return ApiScoreSheet::from($sheet, $this->results->canEnter($request->user(), $sheet), $sheet->isEditable() ? $this->results->incomplete($sheet) : 0, $rows)->toArray();
    }

    /** GET /api/v1/teaching/courses: courses assigned this semester (with their sheet, if opened) and recent sheets. */
    public function courses(Request $request): JsonResponse
    {
        $this->staff($request);
        $user = $request->user();
        $ctx = $this->registration->context();
        $mine = $ctx ? $user->courseAssignments()->with('course')->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->get() : collect();
        $sheets = ResultSheet::with('course', 'session', 'semesterType', 'stage', 'scheme.items')->withCount('results')
            ->whereIn('course_id', $user->courseAssignments()->pluck('course_id'))->latest('updated_at')->limit(30)->get()
            ->filter(fn ($s) => $this->results->canEnter($user, $s));
        $current = $ctx ? $sheets->filter(fn ($s) => $s->academic_session_id === $ctx->session->id && $s->semester_type_id === $ctx->type->id)->keyBy('course_id') : collect();

        return $this->ok([
            'term' => $ctx?->label(),
            'courses' => $mine->map(fn ($a) => [
                'course' => ['id' => $a->course->id, 'code' => $a->course->code, 'title' => $a->course->title, 'units' => (int) $a->course->credit_units],
                'sheet' => ($s = $current->get($a->course_id)) ? $this->sheetData($request, $s, false) : null,
            ] + $this->assessmentsFor($a->course, $s, $ctx?->session->id))->sortBy('course.code')->values()->all(),
            'sheets' => $sheets->map(fn ($s) => $this->sheetData($request, $s, false))->values()->all(),
        ]);
    }

    /** POST /api/v1/teaching/courses/{course}/open: the sheet for an assigned course this semester (created if needed). */
    public function open(Request $request, Course $course): JsonResponse
    {
        $this->staff($request);
        $ctx = $this->registration->context() ?? abort(404, 'No semester is running.');
        $sheet = $this->results->sheet($course->id, $ctx->session->id, $ctx->type->id);
        abort_unless($this->results->canEnter($request->user(), $sheet), 403, 'You are not assigned to this course.');

        return $this->ok($this->sheetData($request, $sheet->fresh()));
    }

    /** GET /api/v1/teaching/sheets/{sheet} */
    public function show(Request $request, ResultSheet $sheet): JsonResponse
    {
        $this->staff($request);
        abort_unless($this->mayView($request, $sheet), 404);
        if ($sheet->isEditable() && $this->results->canEnter($request->user(), $sheet)) {
            $this->results->syncRows($sheet);
        }

        return $this->ok($this->sheetData($request, $sheet->fresh()));
    }

    /**
     * PUT /api/v1/teaching/sheets/{sheet}/scores
     * {"scores": {"<student_id>": {"marks": {"<assessment id>": 8.5, ...}}}}  (only the marks sent change; null clears one)
     * On a sheet without a scheme: {"scores": {"<student_id>": {"ca": 20, "exam": 45}}}
     */
    public function save(Request $request, ResultSheet $sheet): JsonResponse
    {
        $this->staff($request);
        abort_unless($this->results->canEnter($request->user(), $sheet), 403, 'You are not assigned to this course.');
        $data = $request->validate([
            'scores' => ['required', 'array', 'max:2000'],
            'scores.*.marks' => ['nullable', 'array'],
            'scores.*.marks.*' => ['nullable', 'numeric'],
            'scores.*.ca' => ['nullable', 'numeric'],
            'scores.*.exam' => ['nullable', 'numeric'],
        ]);

        $scores = [];
        $rows = $sheet->results()->get()->keyBy('student_id');
        foreach ($data['scores'] as $studentId => $s) {
            $row = $rows->get((int) $studentId);
            if (! $row) {
                continue;
            }
            $scores[(int) $studentId] = $sheet->assessment_scheme_id
                ? new Score(items: $s['marks'] ?? [])
                : new Score(array_key_exists('ca', $s) ? $s['ca'] : $row->ca, array_key_exists('exam', $s) ? $s['exam'] : $row->exam);
        }

        try {
            $problems = $this->results->saveScores($sheet, $scores, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['sheet' => [$e->getMessage()]]], 422);
        }

        return $this->ok($this->sheetData($request, $sheet->fresh()), ['saved' => count($scores) - count($problems), 'problems' => $problems]);
    }

    /** POST /api/v1/teaching/sheets/{sheet}/submit */
    public function submit(Request $request, ResultSheet $sheet): JsonResponse
    {
        $this->staff($request);
        abort_unless($this->results->canEnter($request->user(), $sheet), 403, 'You are not assigned to this course.');
        try {
            $this->results->submit($sheet, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['sheet' => [$e->getMessage()]]], 422);
        }

        return $this->ok($this->sheetData($request, $sheet->fresh()), ['message' => 'Scores submitted for approval.']);
    }
}
