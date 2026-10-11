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
            $entries = ProgrammeCourse::with('course')
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
                        'units'   => $rows->where('status', '!=', 'elective')->sum(fn ($e) => $e->course->credit_units),
                        'all'     => $rows->sum(fn ($e) => $e->course->credit_units),
                    ];
                }
            }

            $taken = $entries->pluck('course_id')->all();
            $courseOptions = Course::with('department')
                ->where('is_active', true)
                ->whereNotIn('id', $taken)
                ->orderBy('code')
                ->get();
        }

        return view('academics.curriculum', [
            'pagetitle'     => 'Curriculum',
            'programmes'    => $programmes,
            'programme'     => $programme,
            'grid'          => $grid,
            'courseOptions' => $courseOptions,
            'statuses'      => \App\Support\Lookups::options('course_status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'programme_id'   => ['required', 'exists:programmes,id'],
            'course_ids'     => ['required', 'array', 'min:1'],
            'course_ids.*'   => ['exists:courses,id'],
            'level'          => ['required', 'integer'],
            'semester_order' => ['required', Rule::in(array_keys(\App\Models\SemesterType::options()))],
            'status'         => ['required', Rule::in(array_keys(\App\Support\Lookups::options('course_status')))],
        ]);

        $programme = Programme::findOrFail($data['programme_id']);
        if (! in_array((int) $data['level'], $programme->levels(), true)) {
            return back()->withInput()->with('error', "{$programme->name} does not run {$data['level']} level.");
        }

        $added = 0;
        foreach ($data['course_ids'] as $courseId) {
            $entry = ProgrammeCourse::firstOrCreate(
                ['programme_id' => $programme->id, 'course_id' => $courseId],
                ['level' => $data['level'], 'semester_order' => $data['semester_order'], 'status' => $data['status']]
            );
            $added += $entry->wasRecentlyCreated ? 1 : 0;
        }

        return redirect()->route('academics.curriculum.index', ['programme' => $programme->id])
            ->with('success', "{$added} course(s) added to the curriculum.");
    }

    public function update(Request $request, $entry): RedirectResponse
    {
        $entry = ProgrammeCourse::with('programme')->findOrFail($entry);

        $data = $request->validate([
            'level'          => ['required', Rule::in($entry->programme->levels())],
            'semester_order' => ['required', Rule::in(array_keys(\App\Models\SemesterType::options()))],
            'status'         => ['required', Rule::in(array_keys(\App\Support\Lookups::options('course_status')))],
        ]);

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
