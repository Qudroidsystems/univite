<?php

namespace App\Http\Controllers;

use App\Models\CourseRegistration;
use App\Models\ExamSeat;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableInvigilator;
use App\Services\SeatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** What a student or member of staff sees of the published timetables: classes, papers and seats, teaching and duties. */
class MyScheduleController extends Controller
{
    /** [session id, semester type id] of the current semester. */
    private function current(): array
    {
        $sem = Semester::current();
        $type = $sem ? SemesterType::where('sort_order', $sem->sort_order)->first() : null;

        return [$sem?->academic_session_id, $type?->id, $sem];
    }

    /** The courses a student takes this semester: their course form, or their curriculum if they have not registered yet. */
    private function courses(Student $s, ?int $session, ?int $type): array
    {
        $ids = CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.student_id', $s->id)->where('f.academic_session_id', $session)->where('f.semester_type_id', $type)->where('f.status', '!=', 'rejected')
            ->pluck('course_registrations.course_id')->all();
        if (! $ids && $type) {
            $order = SemesterType::find($type)?->sort_order;
            $ids = ProgrammeCourse::where('programme_id', $s->programme_id)->where('level', $s->current_level)->where('semester_order', $order)->pluck('course_id')->all();
        }

        return $ids;
    }

    private function published(string $kind, ?int $session, ?int $type): Collection
    {
        return Timetable::where(['kind' => $kind, 'academic_session_id' => $session, 'semester_type_id' => $type, 'status' => 'published'])->get();
    }

    public function timetable(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student, 403);
        [$session, $type, $sem] = $this->current();
        $courses = $this->courses($student, $session, $type);
        $tts = $this->published('lecture', $session, $type);
        $entries = TimetableEntry::with('course', 'venue', 'item')->whereIn('timetable_id', $tts->pluck('id'))->whereIn('course_id', $courses)->orderBy('day')->orderBy('starts_at')->get()->unique('unit_key');

        return view('my.timetable', ['pagetitle' => 'My timetable', 'entries' => $entries, 'semester' => $sem, 'timetables' => $tts, 'periods' => collect($tts->first()?->config['periods'] ?? [])]);
    }

    public function exams(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student, 403);
        [$session, $type, $sem] = $this->current();
        $courses = $this->courses($student, $session, $type);
        $tts = $this->published('exam', $session, $type);
        $papers = TimetableEntry::with('course', 'venue')->whereIn('timetable_id', $tts->pluck('id'))->whereIn('course_id', $courses)->orderBy('date')->orderBy('starts_at')->get()->groupBy('unit_key');
        $seats = SeatingService::seatsFor($student)->keyBy(fn ($s) => $s->course_id . '|' . $s->slot);
        $clash = $papers->groupBy(fn ($rows) => $rows->first()->slots[0])->filter(fn ($g) => $g->count() > 1);

        return view('my.exams', ['pagetitle' => 'My examinations', 'papers' => $papers, 'seats' => $seats, 'semester' => $sem, 'clash' => $clash, 'student' => $student, 'timetables' => $tts]);
    }

    public function slip(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student, 403);

        return view('my.exam-slip', ['seats' => SeatingService::seatsFor($student), 'student' => $student->load('programme')]);
    }

    public function teaching(Request $request): View
    {
        $user = $request->user();
        abort_if($user->student, 403);
        [$session, $type, $sem] = $this->current();
        $tts = Timetable::where('status', 'published')->where('academic_session_id', $session)->where('semester_type_id', $type)->get();
        $classes = TimetableEntry::with('course', 'venue', 'item', 'timetable')->whereIn('timetable_id', $tts->where('kind', 'lecture')->pluck('id'))
            ->whereHas('item', fn ($q) => $q->whereJsonContains('staff', $user->id))->orderBy('day')->orderBy('starts_at')->get()->unique('unit_key');
        $papers = TimetableEntry::with('course', 'venue')->whereIn('timetable_id', $tts->where('kind', 'exam')->pluck('id'))
            ->whereHas('item', fn ($q) => $q->whereJsonContains('staff', $user->id))->orderBy('date')->orderBy('starts_at')->get()->groupBy('unit_key');
        $duties = TimetableInvigilator::with('venue', 'timetable')->where('user_id', $user->id)->whereDate('date', '>=', today())
            ->whereHas('timetable', fn ($q) => $q->where('status', 'published'))->orderBy('date')->orderBy('starts_at')->get();

        return view('my.teaching', ['pagetitle' => 'My teaching and duties', 'classes' => $classes, 'papers' => $papers, 'duties' => $duties, 'semester' => $sem]);
    }

    /** Opened by scanning the QR code on a seating slip: who should be in this seat, and check-in. */
    public function verify(Request $request, string $code): View
    {
        abort_unless($request->user()->canAny(['View exam seating', 'Record exam attendance']), 403);
        $seat = ExamSeat::with('student.programme', 'course', 'venue', 'plan')->where('code', strtoupper($code))->firstOrFail();

        return view('seating.verify', ['pagetitle' => 'Seat check', 'seat' => $seat, 'current' => $seat->plan->status === 'published']);
    }

    public function checkIn(Request $request, string $code, SeatingService $svc): RedirectResponse
    {
        abort_unless($request->user()->can('Record exam attendance'), 403);
        $seat = ExamSeat::where('code', strtoupper($code))->firstOrFail();
        $d = $request->validate(['mark' => ['nullable', 'in:present,absent']]);
        $svc->attendance($seat, $d['mark'] ?? null, $request->user());

        return back()->with('success', ! empty($d['mark']) ? 'Marked ' . $d['mark'] . '.' : 'Attendance cleared.');
    }
}
