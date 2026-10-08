<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasProfileFields;
use App\Models\ApplicantDocument;
use App\Models\Student;
use App\Services\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** A newly admitted student's own steps: accept the offer, pay, biodata, documents. Ownership-checked, no staff permission. */
class StudentOnboardingController extends Controller
{
    use HasProfileFields;

    public function __construct(private OnboardingService $onboarding)
    {
    }

    private function student(): Student
    {
        $student = auth()->user()->student ?? abort(403, 'This page is for students.');
        abort_unless($student->needs_onboarding, 404);

        return $student;
    }

    public function index(): View
    {
        $student = $this->student()->load('programme.department.faculty', 'entrySession', 'applicant.documents');

        return view('students.onboarding', [
            'pagetitle' => 'Getting started',
            'student'   => $student,
            'checklist' => $this->onboarding->checklist($student),
            'invoice'   => $this->onboarding->acceptanceInvoice($student),
            'locked'    => $this->onboarding->isLocked($student),
            'documents' => $this->onboarding->documents($student),
            'required'  => $this->onboarding->requiredDocuments($student),
            'states'    => $this->states(),
            'paymentInstructions' => \App\Services\ApplicationSettings::paymentInstructions(),
        ]);
    }

    public function accept(): RedirectResponse
    {
        try {
            $this->onboarding->acceptOffer($this->student());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'You have accepted your admission offer. Congratulations!');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $student = $this->student();
        abort_if($this->onboarding->isLocked($student), 403, 'You have been verified, so your biodata can no longer be changed here.');

        $rules = collect($this->profileRules())->only(['phone_number', 'gender', 'date_of_birth', 'state_of_origin', 'lga', 'nationality', 'address', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_address'])->all();
        foreach (array_diff(array_keys($rules), ['guardian_address']) as $field) {
            $rules[$field] = array_merge(['required'], array_values(array_diff($rules[$field], ['nullable'])));
        }

        $student->update($request->validate($rules));
        $student->user->update(['phone_number' => $student->phone_number, 'gender' => $student->gender, 'date_of_birth' => $student->date_of_birth]);

        return back()->with('success', 'Your biodata has been saved.');
    }

    public function storeDocument(Request $request): RedirectResponse
    {
        $student = $this->student();
        $type = (string) $request->input('type');

        $request->validate([
            'type' => ['required', Rule::in(array_keys($this->onboarding->requiredDocuments($student)))],
            'file' => $type === 'passport'
                ? ['required', 'file', 'mimes:jpg,jpeg', 'mimetypes:image/jpeg', 'max:50']
                : ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ], [
            'file.max'   => $type === 'passport' ? 'The passport photograph must be a JPEG of 50 KB or less.' : 'The file must be 2 MB or less.',
            'file.mimes' => $type === 'passport' ? 'The passport photograph must be a JPEG.' : 'Upload a JPG, PNG or PDF file.',
        ]);

        try {
            $this->onboarding->storeDocument($student, $type, $request->file('file'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', ApplicantDocument::TYPES[$type] . ' uploaded.');
    }

    public function showDocument(ApplicantDocument $document)
    {
        $student = $this->student();
        abort_unless($student->applicant_id && $document->applicant_id === $student->applicant_id, 404);

        return response()->file(Storage::disk('local')->path($document->path), ['Content-Disposition' => 'inline; filename="' . addslashes($document->original_name) . '"']);
    }
}
