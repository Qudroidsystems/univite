<?php

namespace App\Http\Controllers;

use App\Models\ApplicantDocument;
use App\Models\Student;
use App\Services\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;

/** Staff side of new-student verification: who is ready, who has been seen, and the documents to check. */
class VerificationController extends Controller
{
    public function __construct(private OnboardingService $onboarding)
    {
        $this->middleware('permission:View students')->only(['index', 'document']);
        $this->middleware('permission:Verify new students')->only(['verify', 'unverify']);
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $students = Student::with(['programme', 'user', 'applicant.documents', 'invoices'])
            ->where('needs_onboarding', true)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('matric_number', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")))
            ->when($request->filled('batch'), fn ($query) => $query->where('admission_batch', $request->query('batch')))
            ->orderBy('last_name')->get()
            ->each(fn ($s) => $s->setAttribute('onboarding_state', $this->onboarding->state($s)));

        $counts = $students->countBy('onboarding_state');
        $tab = in_array($request->query('tab'), ['awaiting', 'ready', 'verified'], true) ? $request->query('tab') : 'ready';

        return view('students.verification', [
            'pagetitle' => 'New Student Verification',
            'students'  => $students->where('onboarding_state', $tab)->values(),
            'counts'    => ['awaiting' => $counts['awaiting'] ?? 0, 'ready' => $counts['ready'] ?? 0, 'verified' => $counts['verified'] ?? 0],
            'tab'       => $tab,
            'batches'   => Student::where('needs_onboarding', true)->whereNotNull('admission_batch')->distinct()->orderBy('admission_batch')->pluck('admission_batch'),
            'onboarding' => $this->onboarding,
        ]);
    }

    public function verify(Request $request, $id): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $this->onboarding->verify(Student::with('applicant.documents')->findOrFail($id), auth()->id(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Student verified.');
    }

    public function unverify($id): RedirectResponse
    {
        $this->onboarding->unverify(Student::findOrFail($id));

        return back()->with('success', 'Verification removed. The student can edit their documents again.');
    }

    public function document($id)
    {
        $document = ApplicantDocument::findOrFail($id);

        return response()->file(Storage::disk('local')->path($document->path), ['Content-Disposition' => 'inline; filename="' . addslashes($document->original_name) . '"']);
    }
}
