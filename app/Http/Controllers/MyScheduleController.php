<?php

namespace App\Http\Controllers;

use App\Models\ExamSeat;
use App\Services\Schedule\MySchedule;
use App\Services\SeatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What a student or member of staff sees of the published timetables: classes, papers and seats, teaching and duties. */
class MyScheduleController extends Controller
{
    public function __construct(private MySchedule $schedule)
    {
    }

    public function timetable(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student, 403);
        $term = $this->schedule->term();
        $tts = $this->schedule->published('lecture', $term);

        return view('my.timetable', ['pagetitle' => 'My timetable', 'entries' => $this->schedule->lectures($student, $term), 'semester' => $term->semester, 'timetables' => $tts, 'periods' => collect($tts->first()?->config['periods'] ?? [])]);
    }

    public function exams(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student, 403);
        $term = $this->schedule->term();
        $exams = $this->schedule->exams($student, $term);

        return view('my.exams', ['pagetitle' => 'My examinations', 'papers' => $exams->papers, 'seats' => $exams->seats, 'semester' => $term->semester, 'clash' => $exams->clash, 'student' => $student, 'timetables' => $this->schedule->published('exam', $term)]);
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
        $term = $this->schedule->term();
        $t = $this->schedule->teaching($user, $term);

        return view('my.teaching', ['pagetitle' => 'My teaching and duties', 'classes' => $t->classes, 'papers' => $t->papers, 'duties' => $t->duties, 'semester' => $term->semester]);
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
