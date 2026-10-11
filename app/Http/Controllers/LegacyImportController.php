<?php

namespace App\Http\Controllers;

use App\Data\Legacy\ColumnMapping;
use App\Data\Legacy\ImportField;
use App\Data\Legacy\ImportOptions;
use App\Models\LegacyImport;
use App\Services\LegacyImportService;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Upload old results or previous totals, match the columns, check the file, import it. */
class LegacyImportController extends Controller
{
    public function __construct(private LegacyImportService $imports)
    {
        $this->middleware('permission:Import legacy results');
    }

    public function index(): View
    {
        return view('legacy.index', [
            'pagetitle' => 'Legacy results', 'imports' => LegacyImport::with('creator')->latest('id')->paginate(20),
            'fields' => collect(LegacyImportService::KINDS)->mapWithKeys(fn ($k) => [$k => ImportField::for($k)]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(LegacyImportService::KINDS)], 'file' => ['required', 'file', 'max:51200', 'mimes:' . implode(',', SpreadsheetReader::EXTENSIONS)]]);
        try {
            $import = $this->imports->upload($d['kind'], $request->file('file'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('legacy.show', $import)->with('success', 'Uploaded. Check that each field is matched to the right column, then check the file.');
    }

    public function show(LegacyImport $import): View
    {
        return view('legacy.show', [
            'pagetitle' => $import->file_name, 'import' => $import, 'fields' => ImportField::for($import->kind),
            'mapping' => $import->columnMapping(), 'options' => $import->importOptions(), 'report' => $import->reportData(),
        ]);
    }

    public function check(Request $request, LegacyImport $import): RedirectResponse
    {
        $request->validate(['map' => ['nullable', 'array'], 'options' => ['nullable', 'array']]);
        try {
            $report = $this->imports->check($import, ColumnMapping::fromArray($request->input('map'), $import->headers), ImportOptions::fromArray($request->input('options', [])));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($report->ok ? 'success' : 'error', $report->ok
            ? "{$report->ok} row(s) are ready to import" . ($report->skipped() ? ", {$report->skipped()} will be left out (see below)." : '.')
            : 'No row is ready to import. See the problems below.');
    }

    public function import(Request $request, LegacyImport $import): RedirectResponse
    {
        try {
            $report = $this->imports->import($import, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Imported {$report->imported} " . ($import->kind === 'balances' ? 'previous total(s)' : 'result(s)') . " for {$report->students} student(s). It can be undone from the operation history.");
    }

    /** The rows left out and why, as a CSV to fix in the original file. */
    public function problems(LegacyImport $import): StreamedResponse
    {
        $report = $import->reportData() ?? abort(404);

        return response()->streamDownload(function () use ($report) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Row', 'Matric number', 'Problem', 'Kind']);
            foreach ($report->problems as $p) {
                fputcsv($h, [$p->row, $p->matric, $p->message, 'Left out']);
            }
            foreach ($report->warnings as $w) {
                fputcsv($h, [$w->row, $w->matric, $w->message, 'Warning']);
            }
            fclose($h);
        }, pathinfo($import->file_name, PATHINFO_FILENAME) . '-problems.csv', ['Content-Type' => 'text/csv']);
    }

    /** An empty file with the headings the importer recognises. */
    public function template(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, LegacyImportService::KINDS, true), 404);
        $fields = ImportField::for($kind);
        $sample = $kind === 'results'
            ? ['AFE/2016/0001', '2016/2017', 'First', 'GSE 101', 'Use of English I', 2, 25, 48, '', '', '']
            : ['AFE/2016/0001', 96, 312.5, 3.26, 90, '2019/2020', 'From the old records system'];

        return response()->streamDownload(function () use ($fields, $sample) {
            $h = fopen('php://output', 'w');
            fputcsv($h, array_map(fn ($f) => $f->label, array_values($fields)));
            fputcsv($h, $sample);
            fclose($h);
        }, "legacy-{$kind}-template.csv", ['Content-Type' => 'text/csv']);
    }

    public function destroy(LegacyImport $import): RedirectResponse
    {
        if ($import->status === 'imported') {
            return back()->with('error', 'An imported file is undone from the operation history, not deleted.');
        }
        Storage::disk('local')->delete($import->path);
        $import->delete();

        return redirect()->route('legacy.index')->with('success', 'Upload removed.');
    }
}
