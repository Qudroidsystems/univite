<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Models\PeopleImport;
use App\Services\Students\PeopleImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Import Students / Import Staff: template, upload, check, import, undo. */
class PeopleImportController extends Controller
{
    public function __construct(private PeopleImportService $imports)
    {
    }

    private function allow(Request $request, string $kind): void
    {
        abort_unless(in_array($kind, PeopleImportService::KINDS, true), 404);
        abort_unless($request->user()->can($kind === 'students' ? 'Import students' : 'Import staff'), 403);
    }

    public function index(Request $request, string $kind): View
    {
        $this->allow($request, $kind);

        return view('imports.people', [
            'pagetitle' => 'Import ' . $kind, 'kind' => $kind, 'columns' => PeopleImportService::COLUMNS[$kind],
            'imports' => PeopleImport::with('creator')->where('kind', $kind)->latest('id')->limit(30)->get(),
        ]);
    }

    public function template(Request $request, string $kind): StreamedResponse
    {
        $this->allow($request, $kind);
        $rows = $this->imports->template($kind);

        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            foreach ($rows as $r) {
                fputcsv($h, $r);
            }
            fclose($h);
        }, "{$kind}-import-template.csv", ['Content-Type' => 'text/csv']);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $this->allow($request, $kind);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240']]);
        try {
            $import = $this->imports->upload($kind, $request->file('file'), $request->user());
            $check = $this->imports->check($import);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('imports.' . $kind)->with('success', count($check->good) . ' of ' . $check->rows . ' rows are ready to import. Look through the check below, then press Import.');
    }

    public function import(Request $request, string $kind, PeopleImport $import): RedirectResponse
    {
        $this->allow($request, $kind);
        try {
            $n = $this->imports->import($import, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$n} " . ($kind === 'students' ? 'students' : 'members of staff') . ' added. They sign in with "Forgot password", or you can reset their password from their profile.');
    }

    public function undo(Request $request, string $kind, PeopleImport $import): RedirectResponse
    {
        $this->allow($request, $kind);
        try {
            $this->imports->undo($import, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The import has been undone.');
    }
}
