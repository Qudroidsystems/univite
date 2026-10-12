<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\SalaryScale;
use App\Models\SalaryStructure;
use App\Services\OperationAudit;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** The annual basic salary for every grade level and step of a structure: typed into a grid or read from a spreadsheet. */
class SalaryScaleController extends Controller
{
    public function __construct(private SpreadsheetReader $reader, private OperationAudit $audit)
    {
        $this->middleware('permission:Set payroll policy|View payroll')->only('show');
        $this->middleware('permission:Set payroll policy')->only('update', 'upload');
    }

    public function show(SalaryStructure $structure): View
    {
        return view('payroll.scale', [
            'pagetitle' => $structure->code . ' salary scale', 's' => $structure,
            'grid' => $structure->scales()->get()->mapWithKeys(fn ($r) => ["{$r->grade_level}-{$r->step}" => $r->annual_basic]),
        ]);
    }

    public function update(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $d = $request->validate(['amount' => ['array'], 'amount.*' => ['array'], 'amount.*.*' => ['nullable', 'numeric', 'min:0', 'max:999999999']]);
        $rows = [];
        foreach ($d['amount'] ?? [] as $grade => $steps) {
            foreach ($steps as $step => $v) {
                $rows[] = [(int) $grade, (int) $step, $v];
            }
        }

        return back()->with('success', $this->save($structure, $rows, $request) . ' amount(s) saved.');
    }

    /** A spreadsheet with columns Grade, Step and Annual basic (headings in the first row). */
    public function upload(Request $request, SalaryStructure $structure): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:2048']]);
        $file = $request->file('file');
        $table = $this->reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        array_shift($table);
        $rows = [];
        foreach ($table as $line) {
            if (count($line) >= 3 && is_numeric($line[0]) && is_numeric($line[1])) {
                $rows[] = [(int) $line[0], (int) $line[1], str_replace([',', '₦', ' '], '', (string) $line[2])];
            }
        }
        if (! $rows) {
            return back()->with('error', 'No rows found. The file needs three columns: Grade, Step and Annual basic, with headings in the first row.');
        }

        return back()->with('success', $this->save($structure, $rows, $request) . ' amount(s) read from the file and saved.');
    }

    /** @param  array<int, array{0: int, 1: int, 2: mixed}>  $rows */
    private function save(SalaryStructure $structure, array $rows, Request $request): int
    {
        $n = 0;
        DB::transaction(function () use ($structure, $rows, &$n) {
            foreach ($rows as [$grade, $step, $v]) {
                if ($grade < 1 || $grade > $structure->max_grade || $step < 1 || $step > $structure->max_step) {
                    continue;
                }
                $key = ['salary_structure_id' => $structure->id, 'grade_level' => $grade, 'step' => $step];
                if ($v === null || $v === '') {
                    SalaryScale::where($key)->delete();
                    continue;
                }
                $row = SalaryScale::firstOrNew($key);
                if (! $row->exists || round((float) $row->annual_basic, 2) !== round((float) $v, 2)) {
                    $row->fill(['annual_basic' => round((float) $v, 2)])->save();
                    $n++;
                }
            }
        });
        if ($n) {
            $this->audit->note('payroll_policy', 'payroll', $request->user(), 'salary_scale_changed', $structure, null, ['structure' => $structure->code, 'amounts_changed' => $n]);
        }

        return $n;
    }
}
