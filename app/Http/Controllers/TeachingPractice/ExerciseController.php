<?php

namespace App\Http\Controllers\TeachingPractice;

use App\Data\TeachingPractice\ExerciseInput;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\SemesterType;
use App\Models\TpExercise;
use App\Models\TpPosting;
use App\Models\TpSchool;
use App\Services\Campus\PersonFinder;
use App\Services\TeachingPractice\TeachingPracticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Running a teaching practice exercise: set-up, schools, supervisors, postings, letters and scores. */
class ExerciseController extends Controller
{
    public function __construct(private TeachingPracticeService $tp, private PersonFinder $people)
    {
        $this->middleware('permission:View teaching practice|Set up teaching practice|Post students for teaching practice|Record teaching practice scores|Publish teaching practice scores')->only('index', 'show', 'export');
        $this->middleware('permission:Set up teaching practice')->only('create', 'store', 'edit', 'update', 'destroy', 'schools', 'addSupervisor', 'removeSupervisor', 'enrol');
        $this->middleware('permission:Post students for teaching practice')->only('autoPost', 'post', 'withdraw');
        $this->middleware('permission:Publish teaching practice scores')->only('publish');
    }

    public function index(): View
    {
        return view('tp.index', ['pagetitle' => 'Teaching practice', 'exercises' => TpExercise::with('session')->withCount(['postings', 'postings as posted_count' => fn ($q) => $q->where('status', 'posted')])->latest('id')->get()]);
    }

    public function create(): View
    {
        return view('tp.form', $this->formData(new TpExercise(['academic_session_id' => AcademicSession::current()?->id, 'visits_required' => 2, 'criteria' => TpExercise::DEFAULT_CRITERIA, 'max_choices' => 3])));
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $ex = $this->tp->save(null, ExerciseInput::fromArray($request->validate(ExerciseInput::rules())), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tp.exercises.show', $ex)->with('success', 'Exercise created. Choose its schools next.');
    }

    public function show(Request $request, TpExercise $exercise): View
    {
        $tab = $request->query('tab', 'postings');
        $exercise->load('session', 'course', 'semesterType', 'schools', 'supervisors.user', 'supervisors.school');
        $postings = $exercise->postings()->with('student.programme', 'school', 'assessments')
            ->when($request->query('school'), fn ($q, $s) => $q->where('tp_school_id', $s))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->whereHas('student', fn ($s) => $s->where('matric_number', 'like', "%{$t}%")->orWhere('last_name', 'like', "%{$t}%")))
            ->orderBy('id')->paginate(50)->withQueryString();

        return view('tp.show', [
            'pagetitle' => $exercise->name, 'exercise' => $exercise, 'tab' => $tab, 'postings' => $postings, 'left' => $this->tp->placesLeft($exercise),
            'counts' => $exercise->postings()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'allSchools' => TpSchool::where('is_active', true)->orderBy('name')->get(), 'tpService' => $this->tp,
        ]);
    }

    public function edit(TpExercise $exercise): View
    {
        return view('tp.form', $this->formData($exercise));
    }

    public function update(Request $request, TpExercise $exercise): RedirectResponse
    {
        try {
            $this->tp->save($exercise, ExerciseInput::fromArray($request->validate(ExerciseInput::rules())), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tp.exercises.show', $exercise)->with('success', 'Saved.');
    }

    public function destroy(TpExercise $exercise): RedirectResponse
    {
        if ($exercise->postings()->where('status', 'posted')->exists()) {
            return back()->with('error', 'Students have been posted on this exercise, so it is kept.');
        }
        $exercise->delete();

        return redirect()->route('tp.exercises.index')->with('success', 'Exercise removed.');
    }

    public function schools(Request $request, TpExercise $exercise): RedirectResponse
    {
        $d = $request->validate(['capacity' => ['array'], 'capacity.*' => ['nullable', 'integer', 'min:0', 'max:1000']]);
        try {
            $this->tp->setSchools($exercise, $d['capacity'] ?? []);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Schools saved.');
    }

    public function addSupervisor(Request $request, TpExercise $exercise): RedirectResponse
    {
        $d = $request->validate(['person' => ['required', 'string', 'max:100'], 'school_id' => ['required', 'exists:tp_schools,id']]);
        $user = $this->people->find($d['person']);
        if (! $user) {
            return back()->withInput()->with('error', 'No member of staff found with that staff number or email.');
        }
        try {
            $this->tp->addSupervisor($exercise, TpSchool::findOrFail($d['school_id']), $user);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $user->name . ' added as a supervisor.');
    }

    public function removeSupervisor(TpExercise $exercise, \App\Models\TpSupervisor $supervisor): RedirectResponse
    {
        abort_unless($supervisor->tp_exercise_id === $exercise->id, 404);
        $supervisor->delete();

        return back()->with('success', 'Supervisor removed.');
    }

    public function enrol(TpExercise $exercise): RedirectResponse
    {
        return back()->with('success', $this->tp->enrol($exercise) . ' student(s) added to the exercise.');
    }

    public function autoPost(Request $request, TpExercise $exercise): RedirectResponse
    {
        $r = $this->tp->autoPost($exercise, $request->user());

        return back()->with($r->unplaced ? 'warning' : 'success', $r->posted . ' student(s) posted.' . ($r->unplaced ? ' ' . $r->unplaced . ' could not be posted: add schools or places.' : ''));
    }

    public function post(Request $request, TpPosting $posting): RedirectResponse
    {
        $d = $request->validate(['school_id' => ['required', 'exists:tp_schools,id'], 'over' => ['nullable', 'boolean']]);
        try {
            $this->tp->post($posting, TpSchool::findOrFail($d['school_id']), $request->user(), (bool) ($d['over'] ?? false));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $posting->student->full_name . ' posted to ' . $posting->fresh()->school->name . '.');
    }

    public function withdraw(TpPosting $posting): RedirectResponse
    {
        try {
            $this->tp->withdraw($posting);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Withdrawn from the exercise.');
    }

    public function publish(Request $request, TpExercise $exercise): RedirectResponse
    {
        try {
            $r = $this->tp->publish($exercise, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $notes = array_merge(
            array_map(fn ($s) => $s . ': not every visit has been marked', $r->incomplete),
            array_map(fn ($s) => $s . ': not registered for ' . $exercise->course->code, $r->notRegistered),
            $r->problems,
        );

        return back()->with('success', $r->saved . ' score(s) written to the ' . $exercise->course->code . ' result sheet. Submit the sheet there when ready.')->with('skipped', $notes);
    }

    public function letter(TpPosting $posting): View
    {
        abort_unless($posting->status === 'posted', 404);

        return view('tp.letter', ['pagetitle' => 'Posting letter', 'posting' => $posting->load('exercise', 'school', 'student.programme')]);
    }

    public function export(TpExercise $exercise): StreamedResponse
    {
        $rows = $exercise->postings()->with('student.programme', 'school', 'assessments')->orderBy('tp_school_id')->get();

        return response()->streamDownload(function () use ($rows, $exercise) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Matric number', 'Name', 'Programme', 'Subject', 'Status', 'School', 'Town', ...array_map(fn ($n) => 'Visit ' . $n . ' %', range(1, $exercise->visits_required)), 'Final %']);
            foreach ($rows as $p) {
                $visits = $p->assessments->keyBy('visit_no');
                fputcsv($out, [$p->student->matric_number, $p->student->full_name, $p->student->programme?->name, $p->subject, TpPosting::STATUSES[$p->status], $p->school?->name, $p->school?->town,
                    ...array_map(fn ($n) => $visits[$n]->percent ?? '', range(1, $exercise->visits_required)), $this->tp->finalScore($p) ?? '']);
            }
            fclose($out);
        }, \Illuminate\Support\Str::slug($exercise->name) . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function formData(TpExercise $exercise): array
    {
        return ['pagetitle' => $exercise->exists ? 'Exercise settings' : 'New teaching practice exercise', 'exercise' => $exercise,
            'sessions' => AcademicSession::orderByDesc('name')->get(), 'semesters' => SemesterType::orderBy('sort_order')->get(),
            'courses' => Course::where('is_active', true)->orderBy('code')->get(['id', 'code', 'title'])];
    }
}
