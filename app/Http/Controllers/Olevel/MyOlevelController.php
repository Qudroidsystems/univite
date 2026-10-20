<?php

namespace App\Http\Controllers\Olevel;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentOlevelSitting;
use App\Services\Olevel\OlevelService;
use App\Support\Lookups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** A student's own O Level results: enter each sitting, upload the result, see what was read from it, submit for confirmation. */
class MyOlevelController extends Controller
{
    public function __construct(private OlevelService $olevel)
    {
    }

    private function student(): Student
    {
        return auth()->user()->student ?? abort(403, 'This page is for students.');
    }

    private function own(StudentOlevelSitting $sitting): void
    {
        abort_unless($sitting->student_id === $this->student()->id, 404);
    }

    public function index(Request $request): View
    {
        $student = $this->student()->load('programme.department');
        $this->olevel->importFromApplication($student);
        $sittings = $student->olevelSittings()->with('results', 'reviewer')->get();
        $editing = $request->filled('edit') ? $sittings->firstWhere('id', (int) $request->query('edit')) : null;

        return view('olevel.mine', [
            'pagetitle' => 'My O Level Results', 'student' => $student, 'sittings' => $sittings, 'editing' => $editing?->isEditable() ? $editing : null,
            'check' => $this->olevel->evaluate($student),
            'preview' => $sittings->isNotEmpty() ? $this->olevel->evaluate($student, $sittings, 'student') : null,
            'requirement' => $this->olevel->requirementFor($student->programme),
            'subjects' => Lookups::options('olevel_subject'), 'exams' => StudentOlevelSitting::EXAMS, 'grades' => StudentOlevelSitting::GRADES,
            'aiOn' => $this->olevel->aiAvailable(), 'needsDocument' => OlevelService::setting('require_document'),
        ]);
    }

    private function rules(): array
    {
        return [
            'exam_type' => ['required', Rule::in(array_keys(StudentOlevelSitting::EXAMS))],
            'exam_year' => ['required', 'integer', 'between:1980,' . now()->year],
            'exam_number' => ['required', 'string', 'max:30'],
            'centre' => ['nullable', 'string', 'max:150'],
            'subjects' => ['required', 'array', 'max:12'],
            'subjects.*.subject' => ['nullable', 'string', 'max:100'],
            'subjects.*.grade' => ['nullable', Rule::in(StudentOlevelSitting::GRADES)],
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, StudentOlevelSitting $sitting): RedirectResponse
    {
        $this->own($sitting);

        return $this->save($request, $sitting);
    }

    private function save(Request $request, ?StudentOlevelSitting $sitting): RedirectResponse
    {
        $data = $request->validate($this->rules(), ['document.max' => 'The result file must be 5 MB or less.', 'document.mimes' => 'Upload a PDF, JPG or PNG of your result.']);
        $student = $this->student();
        try {
            $sitting = $this->olevel->saveSitting($student, $data, $data['subjects'], $request->file('document'), $sitting);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $message = 'Your ' . $sitting->label() . ' result has been saved.';
        if ($request->hasFile('document') && $this->olevel->aiAvailable()) {
            $sitting = $this->olevel->read($sitting, $request->user());
            $message .= match ($sitting->extraction_status) {
                'done' => ' We read your uploaded result: check the grades we found against yours below, then submit.',
                default => ' Your upload could not be read automatically; the school will check it by eye.',
            };
        }
        if ($request->boolean('submit')) {
            try {
                $this->olevel->submit($sitting);
                $message = 'Your ' . $sitting->label() . ' result has been sent to the school to confirm.';
            } catch (RuntimeException $e) {
                return redirect()->route('my.olevel')->with('error', $e->getMessage());
            }
        }

        return redirect()->route('my.olevel')->with('success', $message);
    }

    public function submit(StudentOlevelSitting $sitting): RedirectResponse
    {
        $this->own($sitting);
        try {
            $this->olevel->submit($sitting);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sent to the school to confirm. You will be notified when it is done.');
    }

    public function read(Request $request, StudentOlevelSitting $sitting): RedirectResponse
    {
        $this->own($sitting);
        abort_unless($sitting->isEditable(), 403);
        try {
            $sitting = $this->olevel->read($sitting, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($sitting->extraction_status === 'done' ? 'success' : 'error', $sitting->extraction_status === 'done' ? 'Your uploaded result has been read. Compare the grades below.' : ($sitting->extraction_note ?: 'Your upload could not be read automatically.'));
    }

    public function destroy(StudentOlevelSitting $sitting): RedirectResponse
    {
        $this->own($sitting);
        abort_unless($sitting->isEditable(), 403, 'A submitted sitting cannot be removed.');
        if ($sitting->document_path) {
            Storage::disk('local')->delete($sitting->document_path);
        }
        $sitting->delete();

        return back()->with('success', 'Sitting removed.');
    }

    public function document(StudentOlevelSitting $sitting)
    {
        $this->own($sitting);
        abort_unless($sitting->document_path && Storage::disk('local')->exists($sitting->document_path), 404);

        return response()->file(Storage::disk('local')->path($sitting->document_path), ['Content-Disposition' => 'inline; filename="' . addslashes((string) $sitting->document_name) . '"']);
    }
}
