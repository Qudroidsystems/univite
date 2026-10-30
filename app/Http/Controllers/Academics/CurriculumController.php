<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Department;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Programme curriculum: which courses a programme runs, at which level and semester.
 */
class CurriculumController extends Controller
{
    public function __construct(private \App\Support\AcademicRules $rules)
    {
        $this->middleware('permission:View programme curriculum')->only('index');
        $this->middleware('permission:Edit programme curriculum')->only(['store', 'update', 'destroy']);
    }

    public function index(Request $request): View
    {
        $programmes = Programme::with('department.faculty')->orderBy('name')->get();
        $programme  = $programmes->firstWhere('id', (int) $request->query('programme'));

        $grid = [];
        $courseOptions = [];

        if ($programme) {
            $entries = ProgrammeCourse::with('course.department', 'courseOption')
                ->where('programme_id', $programme->id)
                ->get()
                ->sortBy(fn ($e) => $e->course->code);

            $types = \App\Models\SemesterType::pluck('id', 'sort_order');
            $rules = $this->rules;
            foreach ($programme->levels() as $level) {
                foreach (\App\Models\SemesterType::options() as $order => $label) {
                    $rows = $entries->where('level', $level)->where('semester_order', $order)->values();
                    $r = $rules->forProgramme($programme, $level, $types[$order] ?? null);
                    $grid[$level][$order] = [
                        'min'     => (int) $r['min_units'],
                        'max'     => (int) $r['max_units'],
                        'label'   => $label,
                        'entries' => $rows,
                        'units'   => $rows->where('status', '!=', \App\Support\CourseType::ELECTIVE)->sum(fn ($e) => $e->course->credit_units),
                        'all'     => $rows->sum(fn ($e) => $e->course->credit_units),
                    ];
                }
            }

            // Courses the programme may take: its own department's, and those other departments share.
            $taken = $entries->pluck('course_id')->all();
            $courseOptions = Course::with('department')
                ->where('is_active', true)
                ->whereNotIn('id', $taken)
                ->where(fn ($q) => $q->where('department_id', $programme->department_id)->orWhereNotNull('shared_as'))
                ->orderBy('code')
                ->get();
        }

        return view('academics.curriculum', [
            'pagetitle'     => 'Curriculum',
            'programmes'    => $programmes,
            'programme'     => $programme,
            'grid'          => $grid,
            'courseOptions' => $courseOptions,
            'statuses'      => \App\Support\CourseType::options(),
            'kinds'         => ProgrammeCourse::KINDS,
            'options'       => $programme ? \App\Models\CourseOption::where('programme_id', $programme->id)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    /** The courses a programme may not take as asked, with why; empty when all are fine. @return string[] */
    private function sharingProblems(Programme $programme, iterable $courses, ?string $status): array
    {
        $problems = [];
        foreach ($courses as $course) {
            $type = $status ?: \App\Support\CourseSharing::defaultType($course, $programme);
            $why = $type ? \App\Support\CourseSharing::problem($course, $programme, $type) : \App\Support\CourseSharing::problem($course, $programme, \App\Support\CourseType::ELECTIVE);
            if ($why) {
                $problems[] = $why;
            }
        }

        return $problems;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'programme_id'   => ['required', 'exists:programmes,id'],
            'course_ids'     => ['required', 'array', 'min:1'],
            'course_ids.*'   => ['exists:courses,id'],
            'level'          => ['required', 'integer'],
            'semester_order' => ['required', Rule::in(array_keys(\App\Models\SemesterType::options()))],
            'status'         => ['nullable', Rule::in(array_keys(\App\Support\CourseType::options()))],
            'kind'           => ['nullable', Rule::in(array_keys(ProgrammeCourse::KINDS))],
            'course_option_id' => ['nullable', Rule::exists('course_options', 'id')->where('programme_id', $request->input('programme_id'))],
        ]);

        $programme = Programme::findOrFail($data['programme_id']);
        if (! in_array((int) $data['level'], $programme->levels(), true)) {
            return back()->withInput()->with('error', "{$programme->name} does not run {$data['level']} level.");
        }

        // A course from another department goes in only the way that department shares it.
        $courses = Course::with('department')->whereIn('id', $data['course_ids'])->get();
        if ($problems = $this->sharingProblems($programme, $courses, $data['status'] ?? null)) {
            return back()->withInput()->with('error', implode(' ', $problems));
        }

        $added = 0;
        foreach ($courses as $course) {
            // no type chosen: the course's own type in its department, or the type it is shared as
            $entry = ProgrammeCourse::firstOrCreate(
                ['programme_id' => $programme->id, 'course_id' => $course->id],
                ['level' => $data['level'], 'semester_order' => $data['semester_order'], 'status' => ($data['status'] ?? null) ?: \App\Support\CourseSharing::defaultType($course, $programme),
                    'kind' => ($data['kind'] ?? null) ?: 'normal', 'course_option_id' => $data['course_option_id'] ?? null]
            );
            $added += $entry->wasRecentlyCreated ? 1 : 0;
        }

        return redirect()->route('academics.curriculum.index', ['programme' => $programme->id])
            ->with('success', "{$added} course(s) added to the curriculum.");
    }

    public function update(Request $request, $entry): RedirectResponse
    {
        $entry = ProgrammeCourse::with('programme', 'course.department')->findOrFail($entry);

        $data = $request->validate([
            'level'          => ['required', Rule::in($entry->programme->levels())],
            'semester_order' => ['required', Rule::in(array_keys(\App\Models\SemesterType::options()))],
            'status'         => ['required', Rule::in(array_keys(\App\Support\CourseType::options())), function ($attr, $value, $fail) use ($entry) {
                if ($why = \App\Support\CourseSharing::problem($entry->course, $entry->programme, (string) $value)) {
                    $fail($why);
                }
            }],
            'kind'           => ['nullable', Rule::in(array_keys(ProgrammeCourse::KINDS))],
            'course_option_id' => ['nullable', Rule::exists('course_options', 'id')->where('programme_id', $entry->programme_id)],
        ]);
        $data['kind'] = ($data['kind'] ?? null) ?: 'normal';
        $data['course_option_id'] = ($data['course_option_id'] ?? null) ?: null;

        $entry->update($data);

        return redirect()->route('academics.curriculum.index', ['programme' => $entry->programme_id])
            ->with('success', 'Curriculum entry updated.');
    }

    public function destroy($entry): RedirectResponse
    {
        $entry = ProgrammeCourse::findOrFail($entry);
        $programmeId = $entry->programme_id;
        $entry->delete();

        return redirect()->route('academics.curriculum.index', ['programme' => $programmeId])
            ->with('success', 'Course removed from the curriculum.');
    }
}
