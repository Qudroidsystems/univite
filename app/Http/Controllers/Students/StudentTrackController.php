<?php

namespace App\Http\Controllers\Students;

use App\Data\Tracks\TrackScope;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Models\TrackMigration;
use App\Services\Students\StudentTrackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Move to Next Semester: preview who moves where, move them, and undo a move. */
class StudentTrackController extends Controller
{
    public function __construct(private StudentTrackService $tracks)
    {
        $this->middleware('permission:View student tracks|Change student semester and level')->only('index');
        $this->middleware('permission:Change student semester and level')->only(['store', 'undo']);
    }

    private function rules(): array
    {
        return [
            'from_session_id' => ['required', 'exists:academic_sessions,id'], 'from_semester_type_id' => ['required', 'exists:semester_types,id'],
            'to_session_id' => ['required', 'exists:academic_sessions,id'], 'to_semester_type_id' => ['required', 'exists:semester_types,id'],
            'faculty_id' => ['nullable', 'exists:faculties,id'], 'department_id' => ['nullable', 'exists:departments,id'], 'study_category_id' => ['nullable', 'exists:study_categories,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'], 'level' => ['nullable', 'integer'], 'matric' => ['nullable', 'string', 'max:40'], 'need_results' => ['nullable', 'boolean'],
        ];
    }

    public function index(Request $request): View
    {
        $current = Semester::current();
        $types = SemesterType::where('is_active', true)->orderBy('sort_order')->get();
        // The usual next step: the next semester of this session, or the first semester of the next session.
        $next = $current ? $types->firstWhere('sort_order', '>', $current->sort_order) : null;
        $defaults = [
            'from_session_id' => $current?->academic_session_id, 'from_semester_type_id' => $types->firstWhere('sort_order', $current?->sort_order)?->id ?? $types->first()?->id,
            'to_session_id' => $next ? $current?->academic_session_id : AcademicSession::where('name', '>', (string) $current?->session?->name)->orderBy('name')->value('id') ?? $current?->academic_session_id,
            'to_semester_type_id' => $next?->id ?? $types->first()?->id,
        ];
        $input = $request->query() + $defaults;
        $plan = null;
        $error = null;
        if ($request->boolean('preview')) {
            $data = validator($input, $this->rules())->validate();
            try {
                $plan = $this->tracks->plan(TrackScope::fromArray($data));
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('students.tracks', [
            'pagetitle' => 'Move to next semester', 'input' => $input, 'plan' => $plan, 'error' => $error,
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'), 'types' => $types->pluck('name', 'id'),
            'faculties' => Faculty::orderBy('name')->pluck('name', 'id'), 'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'categories' => StudyCategory::orderBy('name')->pluck('name', 'id'), 'programmes' => Programme::orderBy('name')->pluck('name', 'id'),
            'runs' => TrackMigration::with('fromSession', 'fromSemester', 'toSession', 'toSemester', 'creator')->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        try {
            $run = $this->tracks->migrate(TrackScope::fromArray($data), $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tracks.index')->with('success', "{$run->moved} students moved" . ($run->held ? ", {$run->held} held back" : '') . ". Reference {$run->key}.");
    }

    public function undo(Request $request, TrackMigration $run): RedirectResponse
    {
        try {
            $this->tracks->undo($run, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Move {$run->key} undone; the students are back where they were.");
    }
}
