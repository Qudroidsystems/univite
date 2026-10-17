<?php

namespace App\Services\Results;

use App\Data\Results\ComputeQuery;
use App\Data\Results\DepartmentSheet;
use App\Data\Results\DepartmentSheetRow;
use App\Data\Results\KeyContext;
use App\Models\ComputedResult;
use App\Models\CourseResult;
use App\Models\GradeBand;
use App\Models\ResultKey;
use App\Support\GradeScale;
use App\Support\Institution;
use App\Support\Xlsx;
use Illuminate\Contracts\View\Factory as ViewFactory;
use RuntimeException;

/**
 * The department master marks sheet, printed from computed results: one section per programme (and graduating or
 * not), each student with this semester's courses (code, units, score and grade), the semester remark, outstanding
 * courses, present / previous / cumulative TCP, TNU and GPA and the cumulative remark, followed by the key to grade,
 * the key to result and the summary. Comes as Excel, as a printable page and as a PDF.
 */
class DepartmentMasterSheet
{
    public function __construct(private ViewFactory $views)
    {
    }

    public function build(ComputeQuery $q): DepartmentSheet
    {
        $computed = ComputedResult::with('student.user', 'programme.studyCategory')
            ->where('department_id', $q->department->id)->where('level', $q->level)
            ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)
            ->when($q->category, fn ($c) => $c->where('study_category_id', $q->category->id))->get();
        if ($computed->isEmpty()) {
            throw new RuntimeException('Nothing has been computed for ' . $q->title() . ' yet. Click Compute first.');
        }
        $term = CourseResult::with('course')->whereIn('student_id', $computed->pluck('student_id'))
            ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)
            ->whereIn('status', ['submitted', 'approved', 'published'])->get()->groupBy('student_id');

        $rows = $computed->map(fn (ComputedResult $c) => new DepartmentSheetRow(
            $c, $term->get($c->student_id, collect())->sortBy(fn ($r) => $r->course->code)->values(),
        ))->sortBy(fn ($r) => [$r->computed->programme->name, $r->computed->graduating ? 1 : 0, $r->computed->student->matric_number ?? 'zzz'])->values();
        $sections = $rows->groupBy(fn ($r) => $r->computed->programme_id . '-' . ($r->computed->graduating ? 'g' : 'n'));

        // The keys printed at the end: those of the department (for the category, if one was chosen).
        $context = new KeyContext($q->department->faculty_id, $q->department->id, $q->category?->id ?? $computed->first()->study_category_id);

        return new DepartmentSheet(
            query: $q,
            sections: $sections->values(),
            gradeKey: GradeScale::bands($context),
            resultKey: ResultKey::pick(ResultKey::orderByDesc('min_gpa')->get(), $context),
            computedAt: $computed->max('computed_at'),
        );
    }

    /** Letterhead lines: the institution, then the school, department and the sheet's title. */
    public function heading(DepartmentSheet $sheet): array
    {
        $q = $sheet->query;

        return [
            strtoupper(trim(($q->session->name ?? '') . ' ' . ($q->semesterType->name ?? '') . ' examination results')),
            strtoupper($q->department->faculty?->name ?? ''),
            'DEPARTMENT OF ' . strtoupper(preg_replace('/^Department of /i', '', $q->department->name)),
            'MASTER MARKS SHEET' . ($q->category ? ' (' . strtoupper($q->category->name) . ')' : '') . ' · ' . $q->level . ' LEVEL',
        ];
    }

    // ---- Excel ---------------------------------------------------------------------------------------

    /** Courses per line in a student's "current semester" block, as on the printed sheet. */
    private const PER_LINE = 8;

    public function excel(DepartmentSheet $sheet): string
    {
        $width = self::PER_LINE;
        $cols = 1 + $width + 2 + 9 + 2;    // reg no, courses, sem rmk, outstanding, 3 x (TCP TNU GPA), cum rmk, reg no
        $c0 = 1 + $width;                  // first column after the courses
        $head1 = array_merge(['REG NO', 'CURRENT SEMESTER'], array_fill(0, $width - 1, null), ['SEM RMK', 'OUTSTANDING COURSES', 'PRESENT', null, null, 'PREVIOUS', null, null, 'CUMULATIVE', null, null, 'CUM RMK', 'REG NO']);
        $head2 = array_merge([null], array_fill(0, $width, null), [null, null, 'TCP', 'TNU', 'GPA', 'TCP', 'TNU', 'GPA', 'TCP', 'TNU', 'GPA', null, null]);
        $table = [$head1, $head2];
        $merges = [[0, 1, 1, $width], [0, $c0 + 2, 0, $c0 + 4], [0, $c0 + 5, 0, $c0 + 7], [0, $c0 + 8, 0, $c0 + 10]];
        foreach ([0, $c0, $c0 + 1, $c0 + 11, $c0 + 12] as $c) {
            $merges[] = [0, $c, 1, $c];
        }
        $styles = [];
        $n = fn ($v) => (float) round((float) $v, 2);

        foreach ($sheet->sections as $rows) {
            $first = $rows->first()->computed;
            $styles[count($table)] = 'section';
            $merges[] = [count($table), 0, count($table), $cols - 1];
            $table[] = [$this->sectionTitle($first)];
            foreach ($rows as $row) {
                $c = $row->computed;
                $reg = $c->student->matric_number ?? '';
                $outstanding = collect($c->outstanding ?? []);
                $blocks = $row->courses->chunk($width)->values();
                if ($blocks->isEmpty()) {
                    $blocks = collect([collect()]);
                }
                $start = count($table);
                foreach ($blocks as $b => $block) {
                    $block = $block->values();
                    $codes = $units = $marks = array_fill(0, $width, null);
                    foreach ($block as $i => $r) {
                        $codes[$i] = $r->course->code;
                        $units[$i] = '(' . $r->credit_units . ')';
                        $marks[$i] = $r->total === null ? '—' : MasterSheets::score($r->total) . $r->grade;
                    }
                    $tail = array_fill(0, 13, null);
                    if ($b === 0) {
                        $tail = [$c->sem_remark, $outstanding->pluck('code')->implode(' '), $n($c->present_tcp), $c->present_tnu, $n($c->present_gpa),
                            $n($c->previous_tcp), $c->previous_tnu, $n($c->previous_gpa), $n($c->cumulative_tcp), $c->cumulative_tnu, $n($c->cgpa), $c->cum_remark ?? '', $reg];
                    }
                    $table[] = array_merge([$b === 0 ? $reg : null], $codes, $tail);
                    $table[] = array_merge([$b === 0 ? $c->student->full_name : null], $units, [null, $b === 0 ? $outstanding->map(fn ($o) => $o['reason'] . '.')->implode(' ') : null], array_fill(0, 11, null));
                    $table[] = array_merge([null], $marks, array_fill(0, 13, null));
                }
                if ($row->courses->isEmpty() && $c->sem_remark === 'NRI') {
                    $table[$start][1] = 'No Registration Information';
                    $merges[] = [$start, 1, $start, $width];
                }
                $styles[count($table)] = 'footer';
                $merges[] = [count($table), 0, count($table), $cols - 1];
                $table[] = [$this->footer($c)];
            }
        }

        $notes = array_merge(
            [$sheet->query->title(), 'Computed ' . $sheet->computedAt?->format('l j F Y, g:i a') . '.', '',
                'TCP: total credit points (grade points x units). TNU: total number of units. GPA: TCP / TNU.',
                'SEM RMK: ' . collect(\App\Models\ComputedResult::SEM_REMARKS)->map(fn ($v, $k) => "{$k} = {$v}")->implode('; ') . '.',
                'R. under an outstanding course: to be repeated.', '', 'Key to grade:'],
            $sheet->gradeKey->map(fn ($b) => "{$b->letter}: " . MasterSheets::score($b->min_score) . '–' . MasterSheets::score($b->max_score) . ", {$b->points} points" . ($b->rank ? ", {$b->rank}" : '') . ($b->is_pass ? '' : ' (fail)'))->all(),
            ['', 'Key to result:'],
            $sheet->resultKey->map(fn ($k) => "{$k->code}: CGPA " . MasterSheets::score($k->min_gpa) . '–' . MasterSheets::score($k->max_gpa) . ", {$k->rank}, {$k->actionLabel()}" . ($k->activity ? ". {$k->activity}" : ''))->all(),
        );

        $widths = array_merge([20], array_fill(0, $width, 9), [8, 26, 7, 6, 6, 7, 6, 6, 7, 6, 6, 9, 20]);

        return Xlsx::write($table, 'Master marks sheet', [
            'widths' => $widths, 'header_rows' => 2, 'wrap' => [0, $c0 + 1], 'decimals' => [$c0 + 4, $c0 + 7, $c0 + 10], 'merges' => $merges, 'row_styles' => $styles, 'freeze' => 1,
            'notes' => $notes, 'notes_title' => 'About this master marks sheet', 'notes_sheet' => 'Key',
            'letterhead' => Institution::letterhead($this->heading($sheet)), 'color' => Institution::get('primary_color'),
        ]);
    }

    public function sectionTitle(ComputedResult $c): string
    {
        $level = $c->level;

        return $c->programme->name . ' - ' . $level . ' Level (' . ($c->graduating ? 'Graduating' : 'Non-Graduating') . ')';
    }

    /** "Used semester ratio: 8/12 · 8 units outstanding" */
    public function footer(ComputedResult $c): string
    {
        $parts = ['Used semester ratio: ' . $c->semesters_used . ($c->semesters_allowed ? '/' . $c->semesters_allowed : '')];
        if ($c->units_outstanding) {
            $parts[] = $c->units_outstanding . ' units outstanding';
        }
        if ($c->missing) {
            $parts[] = $c->missing . ' registered ' . ($c->missing === 1 ? 'course has' : 'courses have') . ' no submitted score';
        }
        if ($c->rank) {
            $parts[] = $c->rank . ($c->activity ? ': ' . $c->activity : '');
        }

        return implode(' · ', $parts);
    }

    // ---- Print and PDF -------------------------------------------------------------------------------

    public function html(DepartmentSheet $sheet, bool $forPdf = false): string
    {
        return $this->views->make('results.department-master-sheet', [
            'sheet' => $sheet, 'heading' => $this->heading($sheet), 'service' => $this, 'forPdf' => $forPdf,
            'perLine' => self::PER_LINE, 'logo' => Institution::logoDataUri(),
        ])->render();
    }

    public static function pdfAvailable(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    public function pdf(DepartmentSheet $sheet): string
    {
        if (! self::pdfAvailable()) {
            throw new RuntimeException('PDF needs the dompdf package: run "composer install" on the server. Use Print instead and save it as PDF.');
        }
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml($this->html($sheet, true));
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 110, 18, 'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.3, 0.3, 0.3]);
        $canvas->page_text(28, $canvas->get_height() - 22, $sheet->query->title() . ' · printed ' . now()->format('j M Y, g:i a'), null, 7, [0.45, 0.45, 0.45]);

        return (string) $pdf->output();
    }
}
