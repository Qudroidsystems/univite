<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiClass;
use App\Data\Api\ApiPaper;
use App\Data\Api\ApiRegistrationForm;
use App\Data\Api\ApiTranscript;
use App\Services\RegistrationService;
use App\Services\ResultService;
use App\Services\Schedule\MySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's results, registered courses and timetables; a lecturer's teaching. */
class AcademicsController extends ApiController
{
    public function __construct(private ResultService $results, private RegistrationService $registration, private MySchedule $schedule)
    {
    }

    /** GET /api/v1/results: published results by semester with GPA and CGPA. */
    public function results(Request $request): JsonResponse
    {
        return $this->ok(ApiTranscript::from($this->results->transcript($this->student($request)))->toArray());
    }

    /** GET /api/v1/courses: this semester's course form (if any), whether registration is open, and earlier forms. */
    public function courses(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $ctx = $this->registration->context();
        $forms = $student->registrationForms()->with('session', 'semesterType', 'courses.course')->latest('id')->get();
        $current = $ctx ? $forms->first(fn ($f) => $f->academic_session_id === $ctx->session->id && $f->semester_type_id === $ctx->type->id) : null;

        return $this->ok([
            'term' => $ctx?->label(),
            'can_register' => $ctx !== null && $this->registration->blockers($student, $ctx) === [],
            'blockers' => $ctx ? $this->registration->blockers($student, $ctx) : ['No semester is running right now.'],
            'current' => $current ? ApiRegistrationForm::from($current)->toArray() : null,
            'history' => $forms->reject(fn ($f) => $current && $f->id === $current->id)->map(fn ($f) => ApiRegistrationForm::from($f)->toArray())->values()->all(),
            'register_url' => route('my.registration'),
        ]);
    }

    /** GET /api/v1/timetable/lectures: weekly classes for the courses the student takes this semester. */
    public function lectures(Request $request): JsonResponse
    {
        $term = $this->schedule->term();

        return $this->ok($this->schedule->lectures($this->student($request), $term)->map(fn ($e) => ApiClass::from($e)->toArray())->values()->all(),
            ['semester' => $term->semester?->name]);
    }

    /** GET /api/v1/timetable/exams: papers with hall and seat once seating is published. */
    public function exams(Request $request): JsonResponse
    {
        $term = $this->schedule->term();
        $exams = $this->schedule->exams($this->student($request), $term);
        $papers = $exams->papers->map(fn ($rows) => ApiPaper::from($rows, $exams->seats->get($rows->first()->course_id . '|' . $rows->first()->slots[0]))->toArray())->values()->all();

        return $this->ok($papers, ['semester' => $term->semester?->name, 'clashes' => $exams->clash->count(), 'slip_url' => route('my.exams.slip')]);
    }

    /** GET /api/v1/timetable/teaching: a member of staff's classes, papers and invigilation duties. */
    public function teaching(Request $request): JsonResponse
    {
        abort_if($request->user()->student, 403, 'This is only for staff.');
        $term = $this->schedule->term();
        $t = $this->schedule->teaching($request->user(), $term);

        return $this->ok([
            'classes' => $t->classes->map(fn ($e) => ApiClass::from($e)->toArray())->values()->all(),
            'papers' => $t->papers->map(fn ($rows) => ApiPaper::from($rows)->toArray())->values()->all(),
            'duties' => $t->duties->map(fn ($d) => ['date' => $d->date ? substr((string) $d->date, 0, 10) : null, 'starts_at' => substr((string) $d->starts_at, 0, 5), 'ends_at' => substr((string) $d->ends_at, 0, 5), 'venue' => $d->venue?->code, 'role' => $d->role])->values()->all(),
        ], ['semester' => $term->semester?->name]);
    }
}
