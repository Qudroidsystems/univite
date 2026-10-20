<?php

namespace App\Http\Controllers\Senate;

use App\Data\Senate\SenateCourse;
use App\Data\Senate\SenateQuery;
use App\Data\Senate\SenateReason;
use App\Data\Senate\SenateStudent;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\SemesterType;
use App\Models\SenateDecision;
use App\Services\Senate\SenateReview;
use App\Support\Institution;
use App\Support\Levels;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The Senate result approval page: students, courses and summaries at the Senate levels, filtered by any finding,
 * with approve / defer / refer, and every view printable (or saved as PDF) and downloadable as Excel with the letterhead.
 */
class SenateController extends Controller
{
    public const VIEWS = ['students' => 'Students', 'courses' => 'Courses', 'summary' => 'Summary'];

    public function __construct(private SenateReview $review, private \App\Services\Access\ScopedAccess $access)
    {
    }

    private function data(Request $request, string $view): array
    {
        $scope = $this->review->scope($request->user());
        abort_if($scope->isEmpty(), 403);
        $q = SenateQuery::fromRequest($request);
        $students = $view === 'courses' ? collect() : $this->review->students($view === 'summary' ? new SenateQuery($q->session, $q->semester, $q->facultyId, $q->departmentId, $q->programmeId, $q->level, null, null, null, null, null, $q->finalOnly) : $q, $scope);
        $courses = $view === 'students' ? collect() : $this->review->courses($view === 'summary' ? new SenateQuery($q->session, $q->semester, $q->facultyId, $q->departmentId, $q->programmeId, $q->level, null, null, null, null, null, $q->finalOnly) : $q, $scope);

        return [$q, $students, $courses, $view === 'summary' ? $this->review->summary($students, $courses) : null];
    }

    private function view(Request $request): string
    {
        $v = $request->query('view', 'students');

        return array_key_exists($v, self::VIEWS) ? $v : 'students';
    }

    public function index(Request $request): View
    {
        $view = $this->view($request);
        [$q, $students, $courses, $summary] = $this->data($request, $view);
        $scope = $this->review->scope($request->user());

        return view('senate.index', [
            'pagetitle' => 'Senate result approval', 'view' => $view, 'q' => $q, 'students' => $students, 'courses' => $courses, 'summary' => $summary,
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'), 'semesters' => SemesterType::orderBy('sort_order')->pluck('name', 'id'),
            'faculties' => Faculty::orderBy('name')->get()->filter(fn ($f) => $scope->all || Department::where('faculty_id', $f->id)->get()->contains(fn ($d) => $scope->coversDepartment($d->id)))->pluck('name', 'id'),
            'departments' => Department::orderBy('name')->get()->filter(fn ($d) => $scope->coversDepartment($d->id))->pluck('name', 'id'),
            'programmes' => Programme::orderBy('name')->get()->filter(fn ($p) => $scope->coversDepartment($p->department_id))->pluck('name', 'id'),
            'levels' => Levels::options(), 'canDecide' => ! $this->scopedDecide($request)->isEmpty(), 'settings' => SenateReview::settings(),
            'canSettings' => $request->user()->can('Edit result settings'),
        ]);
    }

    private function scopedDecide(Request $request): \App\Data\Access\AccessScope
    {
        return $this->access->scope($request->user(), 'Record senate decisions');
    }

    public function export(Request $request, string $format): Response|View
    {
        $view = $this->view($request);
        [$q, $students, $courses, $summary] = $this->data($request, $view);
        $title = 'Senate review · ' . self::VIEWS[$view] . ' · ' . $q->title();
        $lines = array_values(array_filter([$title, $this->filterLine($q), 'Includes results still going through approval. Printed ' . now()->format('j M Y, g:i a') . '.']));

        if ($format === 'excel') {
            $name = Str::slug('senate ' . $view . ' ' . $q->title()) . '.xlsx';

            return response($this->excel($view, $students, $courses, $summary, $lines), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="' . $name . '"',
            ]);
        }
        $data = ['view' => $view, 'q' => $q, 'students' => $students, 'courses' => $courses, 'summary' => $summary, 'lines' => $lines, 'forPdf' => $format === 'pdf'];
        if ($format === 'pdf') {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $pdf = new \Dompdf\Dompdf($options);
            $pdf->loadHtml(view('senate.print', $data)->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            $canvas = $pdf->getCanvas();
            $canvas->page_text($canvas->get_width() - 110, 18, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.3, 0.3, 0.3]);

            return response((string) $pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . Str::slug('senate ' . $view . ' ' . $q->title()) . '.pdf"']);
        }

        return view('senate.print', $data);
    }

    private function filterLine(SenateQuery $q): ?string
    {
        return collect([
            $q->facultyId ? 'Faculty: ' . Faculty::whereKey($q->facultyId)->value('name') : null,
            $q->departmentId ? 'Department: ' . Department::whereKey($q->departmentId)->value('name') : null,
            $q->programmeId ? 'Programme: ' . Programme::whereKey($q->programmeId)->value('name') : null,
            $q->level ? "Level: {$q->level}" : null,
            $q->status ? 'Status: ' . (SenateStudent::STATUSES[$q->status] ?? $q->status) : null,
            $q->flag ? 'Finding: ' . (SenateReason::CODES[$q->flag][1] ?? $q->flag) : null,
            $q->decision ? 'Decision: ' . ($q->decision === 'none' ? 'not yet decided' : SenateDecision::DECISIONS[$q->decision]) : null,
            $q->course ? "Course: {$q->course}" : null,
        ])->filter()->implode(' · ') ?: null;
    }

    private function excel(string $view, $students, $courses, ?array $summary, array $lines): string
    {
        $opts = ['letterhead' => Institution::letterhead($lines), 'freeze' => 3, 'color' => Institution::get('primary_color')];
        if ($view === 'students') {
            $table = [['S/N', 'Matric number', 'Name', 'Programme', 'Department', 'Faculty', 'Level', 'CGPA', 'GPA (session)', 'Class', 'Units passed', 'Units needed', 'Status', 'A. Eligibility', 'B. Result integrity', 'C. Clearance & standing', 'Senate decision', 'Note']];
            foreach ($students->values() as $i => $s) {
                $g = $s->grouped();
                $txt = fn ($k) => implode(' ', array_map(fn ($r) => $r->text, $g[$k] ?? []));
                $table[] = [$i + 1, $s->student->matric_number, $s->student->full_name, $s->student->programme->name, $s->student->programme->department?->name, $s->student->programme->department?->faculty?->name,
                    $s->level, round($s->cgpa, 2), round($s->gpa, 2), $s->class, $s->unitsPassed, $s->unitsNeeded, SenateStudent::STATUSES[$s->status()], $txt('A'), $txt('B'), $txt('C'),
                    $s->decision ? SenateDecision::DECISIONS[$s->decision->decision] : '', $s->decision?->note];
            }

            return Xlsx::write($table, 'Students', $opts + ['widths' => [6, 18, 30, 28, 24, 24, 7, 7, 8, 20, 8, 8, 20, 50, 50, 50, 14, 30]]);
        }
        if ($view === 'courses') {
            $letters = array_keys($courses->first()?->grades ?? []);
            $table = [array_merge(['S/N', 'Code', 'Title', 'Semester', 'Units', 'Department', 'Lecturers', 'Registered', 'With result', 'Passed', 'Failed', 'Pass rate %', 'Average', 'Dept. average'], $letters, ['Sheet', 'Findings', 'Senate decision'])];
            foreach ($courses->values() as $i => $c) {
                $table[] = array_merge([$i + 1, $c->course->code, $c->course->title, $c->semester, $c->course->credit_units, $c->course->department?->name, implode(', ', $c->lecturers),
                    $c->registered, $c->withResult, $c->passed, $c->failed, $c->passRate(), $c->average, $c->departmentAverage], array_values($c->grades),
                    [\App\Models\ResultSheet::STATUSES[$c->sheetStatus] ?? 'Not opened', implode(' ', array_map(fn ($r) => $r->text, $c->reasons)), $c->decision ? SenateDecision::DECISIONS[$c->decision->decision] : '']);
            }

            return Xlsx::write($table, 'Courses', $opts);
        }
        $table = [['Faculty', 'Department', 'Programme', 'Candidates', 'Eligible', 'Not eligible', 'Needs decision', 'Approved', 'Average CGPA', 'Course pass rate %', 'Classes of degree', 'Commonest reasons']];
        $row = fn ($f, $d, $p, $x) => [$f, $d, $p, $x['candidates'], $x['eligible'], $x['not_eligible'], $x['needs_decision'], $x['approved'], $x['average_cgpa'], $x['pass_rate'],
            collect($x['classes'])->map(fn ($n, $c) => "{$c}: {$n}")->implode('; '), collect($x['reasons'])->map(fn ($n, $r) => "{$r}: {$n}")->implode('; ')];
        foreach ($summary['faculties'] as $f => $fac) {
            $table[] = $row($f, 'All departments', '', $fac['total']);
            foreach ($fac['departments'] as $d => $dep) {
                $table[] = $row($f, $d, 'All programmes', $dep['total']);
                foreach ($dep['programmes'] as $p => $x) {
                    $table[] = $row($f, $d, $p, $x);
                }
            }
        }
        $table[] = $row('Whole review', '', '', $summary['all']);

        return Xlsx::write($table, 'Summary', $opts + ['widths' => [26, 26, 30, 10, 9, 10, 12, 9, 10, 12, 40, 60]]);
    }

    public function decide(Request $request): RedirectResponse
    {
        abort_if($this->scopedDecide($request)->isEmpty(), 403);
        $d = $request->validate([
            'subject_type' => ['required', Rule::in(['student', 'course'])], 'subject_id' => ['required', 'integer'], 'semester_type_id_subject' => ['nullable', 'integer'],
            'decision' => ['required', Rule::in(array_keys(SenateDecision::DECISIONS))], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $q = SenateQuery::fromRequest($request);
        abort_unless($q->session, 422);
        try {
            $this->review->decide($d['subject_type'], (int) $d['subject_id'], $q, $d['decision'], $d['note'] ?? null, $request->user(), isset($d['semester_type_id_subject']) ? (int) $d['semester_type_id_subject'] : null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Senate decision recorded: ' . SenateDecision::DECISIONS[$d['decision']] . '.');
    }

    public function approveEligible(Request $request): RedirectResponse
    {
        abort_if($this->scopedDecide($request)->isEmpty(), 403);
        $q = SenateQuery::fromRequest($request);
        $n = $this->review->approveEligible($q, $request->user(), $request->input('note'));

        return back()->with($n ? 'success' : 'warning', $n ? "{$n} eligible " . Str::plural('student', $n) . ' approved.' : 'No eligible student in this view is waiting for approval.');
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('Edit result settings'), 403);
        $this->review->saveSettings($request->validate([
            'boundary_margin' => ['required', 'numeric', 'between:0,1'], 'gpa_swing' => ['required', 'numeric', 'between:0.1,5'], 'pass_high' => ['required', 'integer', 'between:50,100'],
            'pass_low' => ['required', 'integer', 'between:0,90'], 'a_share' => ['required', 'integer', 'between:5,100'], 'f_share' => ['required', 'integer', 'between:5,100'],
            'avg_gap' => ['required', 'numeric', 'between:1,60'], 'min_takers' => ['required', 'integer', 'between:1,100'], 'late_days' => ['required', 'integer', 'between:0,180'],
            'check_fees' => ['nullable', 'boolean'], 'check_library' => ['nullable', 'boolean'], 'check_hostel' => ['nullable', 'boolean'],
        ]));

        return back()->with('success', 'Senate checks saved.');
    }
}
