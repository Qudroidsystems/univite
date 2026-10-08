<?php

namespace App\Http\Controllers;

use App\Models\MatricEvent;
use App\Models\Programme;
use App\Models\Student;
use App\Services\MatricService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Matric numbers after clearance: who is waiting, who has one, and the generate / special / import / cancel / restore / undo tools. */
class MatricController extends Controller
{
    public function __construct(private MatricService $matric)
    {
        $this->middleware('permission:View matric numbers')->only('index');
        $this->middleware('permission:Generate matric numbers')->only('generate');
        $this->middleware('permission:Assign special matric numbers')->only('special');
        $this->middleware('permission:Import external matric numbers')->only('import');
        $this->middleware('permission:Cancel matric numbers')->only('cancel');
        $this->middleware('permission:Restore cancelled matric numbers')->only('restore');
        $this->middleware('permission:Undo matric number batches')->only('undo');
    }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['waiting', 'issued', 'cancelled'], true) ? $request->query('tab') : 'waiting';
        $q = trim((string) $request->query('q'));

        $counts = [
            'waiting'   => $this->matric->waiting()->count(),
            'issued'    => Student::whereNotNull('matric_number')->count(),
            'cancelled' => $this->matric->cancelled()->count(),
        ];

        $base = match ($tab) {
            'issued'    => Student::query()->whereNotNull('matric_number'),
            'cancelled' => $this->matric->cancelled(),
            default     => $this->matric->waiting(),
        };

        $students = $base->with(['programme', 'matricEvents' => fn ($e) => $e->latest('id')])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('matric_number', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")))
            ->when($request->filled('programme_id'), fn ($query) => $query->where('programme_id', $request->query('programme_id')))
            ->orderBy('last_name')->orderBy('first_name')->paginate(30)->withQueryString();

        return view('students.matric', [
            'pagetitle' => 'Matric Numbers',
            'tab'       => $tab,
            'counts'    => $counts,
            'students'  => $students,
            'programmes' => Programme::orderBy('name')->pluck('name', 'id')->all(),
            'batches'   => $this->matric->batches(),
            'preview'   => $this->previewNext(),
        ]);
    }

    private function previewNext(): ?string
    {
        $student = $this->matric->waiting()->with('programme.department.faculty', 'entrySession')->orderBy('last_name')->first();

        return $student ? app(\App\Services\MatricNumberGenerator::class)->preview($student->programme, $student->entrySession) : null;
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'student_ids'   => ['nullable', 'array'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'programme_id'  => ['nullable', 'exists:programmes,id'],
        ]);

        try {
            $r = $this->matric->generateBatch($data['student_ids'] ?? null, $data['programme_id'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$r['issued']} matric number(s) issued in batch {$r['batch']}. Keep the batch code if you may need to undo it.");
    }

    public function special(Request $request, $id): RedirectResponse
    {
        $data = $request->validate(['matric_number' => ['required', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255']]);

        try {
            $this->matric->assignSpecial(Student::findOrFail($id), $data['matric_number'], $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The matric number was assigned.');
    }

    /** A CSV or text file with two columns: application number (or JAMB number, or email), matric number. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $rows = [];
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        while (($cells = fgetcsv($handle)) !== false) {
            if (count($cells) < 2 || (trim($cells[0]) === '' && trim($cells[1]) === '')) {
                continue;
            }
            if (! $rows && preg_match('/matric|number|identifier|email|application/i', $cells[1])) {
                continue; // header row
            }
            $rows[] = [$cells[0], $cells[1]];
        }
        fclose($handle);

        if (! $rows) {
            return back()->with('error', 'The file has no rows. Use two columns: application number (or JAMB number or email), then the matric number.');
        }

        $r = $this->matric->importExternal($rows, $request->user());
        $message = "{$r['applied']} matric number(s) applied" . ($r['applied'] ? " (batch {$r['batch']})" : '') . '.';

        return back()->with($r['applied'] ? 'success' : 'error', $message . ($r['skipped'] ? ' Skipped: ' . implode(' | ', array_slice($r['skipped'], 0, 8)) . (count($r['skipped']) > 8 ? ' …and ' . (count($r['skipped']) - 8) . ' more.' : '') : ''));
    }

    public function cancel(Request $request, $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->matric->cancel(Student::findOrFail($id), $data['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The matric number was cancelled. It will not be issued again unless you restore it.');
    }

    public function restore(Request $request, $id): RedirectResponse
    {
        try {
            $number = $this->matric->restore(Student::findOrFail($id), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$number} was restored.");
    }

    public function undo(Request $request): RedirectResponse
    {
        $data = $request->validate(['batch' => ['required', 'string', 'max:40']]);

        try {
            $n = $this->matric->undoBatch(strtoupper(trim($data['batch'])), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$n} matric number(s) were taken back. Those students are waiting for a number again.");
    }
}
