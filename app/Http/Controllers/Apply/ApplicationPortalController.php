<?php

namespace App\Http\Controllers\Apply;

use App\Http\Controllers\Concerns\HasProfileFields;
use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\ApplicantResult;
use App\Models\Programme;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/** The public online application: apply, sign back in, finish the checklist, submit, print the slip. */
class ApplicationPortalController extends Controller
{
    use HasProfileFields;

    public function __construct(private ApplicationService $applications)
    {
        $this->middleware('applicant')->except(['register', 'store', 'login', 'attempt']);
    }

    // ---- Apply & sign in ----

    public function register(Request $request): View
    {
        return view('apply.register', [
            'open'       => ApplicationSettings::isOpen(),
            'closedWhy'  => ApplicationSettings::closedReason(),
            'session'    => ApplicationSettings::session(),
            'fee'        => ApplicationSettings::fee(),
            'minScore'   => ApplicationSettings::minUtmeScore(),
            'instructions' => ApplicationSettings::instructions(),
            'entryModes' => \App\Models\EntryMode::where('is_active', true)->where('open_online', true)->orderBy('sort_order')->get(),
            'programmes' => Programme::where('is_active', true)->with('department.faculty')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(ApplicationSettings::isOpen(), 403, ApplicationSettings::closedReason());

        // Check uniqueness on the values as they will be stored.
        $request->merge([
            'email'       => strtolower(trim((string) $request->input('email'))),
            'jamb_reg_no' => strtoupper(trim((string) $request->input('jamb_reg_no'))),
        ]);

        $usesUtme = (bool) \App\Models\EntryMode::byCode($request->input('entry_mode'))?->uses_utme;

        $data = $request->validate([
            'first_name'   => ['required', 'string', 'max:100'],
            'last_name'    => ['required', 'string', 'max:100'],
            'other_names'  => ['nullable', 'string', 'max:100'],
            'email'        => ['required', 'email', 'max:255', Rule::unique('applicants', 'email'), Rule::unique('users', 'email')],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'jamb_reg_no'  => ['required', 'regex:/^\d{8}[A-Za-z]{2}$/', Rule::unique('applicants', 'jamb_reg_no')],
            'entry_mode'   => ['required', Rule::in(array_keys(\App\Models\EntryMode::options(true)))],
            'jamb_score'   => ['nullable', 'integer', 'between:0,400', Rule::requiredIf($usesUtme), 'gte:' . ($usesUtme ? ApplicationSettings::minUtmeScore() : 0)],
            'programme_id' => ['required', Rule::exists('programmes', 'id')->where('is_active', true)],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'jamb_reg_no.regex'  => 'A JAMB registration number is 8 digits followed by 2 letters, e.g. 12345678AB.',
            'jamb_reg_no.unique' => 'An application with this JAMB number already exists. Use "Continue my application" to sign in.',
            'email.unique'       => 'This email is already in use. Use "Continue my application" to sign in.',
            'jamb_score.gte'     => 'The minimum UTME score for screening is ' . ApplicationSettings::minUtmeScore() . '.',
            'jamb_score.required' => 'Enter your UTME score.',
        ]);

        unset($data['password_confirmation']);
        $data['email'] = strtolower($data['email']);
        $data['jamb_reg_no'] = strtoupper($data['jamb_reg_no']);
        if (! $usesUtme) {
            $data['jamb_score'] = null;
        }

        $applicant = $this->applications->register($data);
        $request->session()->regenerate();
        $request->session()->put('applicant_id', $applicant->id);

        return redirect()->route('apply.dashboard')->with('success', "Your application number is {$applicant->application_no}. Keep it safe, then complete the checklist below.");
    }

    public function login(): View
    {
        return view('apply.login');
    }

    public function attempt(Request $request): RedirectResponse
    {
        $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);

        $key = 'applicant-login:' . Str::lower($request->input('login')) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $login = trim($request->input('login'));
        $applicant = Applicant::where('email', strtolower($login))->orWhere('jamb_reg_no', strtoupper($login))->orWhere('application_no', $login)->first();

        if (! $applicant || ! $applicant->getAttributes()['password'] || ! Hash::check($request->input('password'), $applicant->getAttributes()['password'])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'These details do not match an application.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('applicant_id', $applicant->id);

        return redirect()->route('apply.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('applicant_id');
        $request->session()->regenerateToken();

        return redirect()->route('apply.login')->with('status', 'You have been signed out.');
    }

    // ---- The application itself ----

    public function dashboard(Request $request): View
    {
        $applicant = $request->attributes->get('applicant')->load('programme.department.faculty', 'session', 'results', 'documents');

        return view('apply.dashboard', [
            'applicant'  => $applicant,
            'editable'   => $this->applications->isEditable($applicant),
            'checklist'  => $this->applications->checklist($applicant),
            'invoice'    => $this->applications->screeningInvoice($applicant),
            'documents'  => ApplicantDocument::TYPES,
            'required'   => $this->applications->requiredDocuments($applicant),
            'states'     => $this->states(),
            'minScore'   => ApplicationSettings::minUtmeScore(),
            'paymentInstructions' => ApplicationSettings::paymentInstructions(),
            'user'       => $applicant->status === 'admitted' ? User::where('email', $applicant->email)->first() : null,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $applicant = $this->editable($request);

        $rules = $this->profileRules();
        foreach (['phone_number', 'gender', 'date_of_birth', 'state_of_origin', 'lga', 'nationality', 'address', 'guardian_name', 'guardian_relationship', 'guardian_phone'] as $required) {
            array_unshift($rules[$required], 'required');
            $rules[$required] = array_values(array_diff($rules[$required], ['nullable']));
        }
        if (\App\Models\EntryMode::byCode($applicant->entry_mode)?->uses_utme) {
            $rules['jamb_score'] = ['required', 'integer', 'between:' . ApplicationSettings::minUtmeScore() . ',400'];
        }

        $applicant->update($request->validate($rules));

        return back()->with('success', 'Your details have been saved.');
    }

    public function storeResult(Request $request): RedirectResponse
    {
        $applicant = $this->editable($request);

        $data = $request->validate([
            'exam_type'   => ['required', Rule::in(array_keys(ApplicantResult::EXAMS))],
            'exam_year'   => ['required', 'integer', 'between:1990,' . now()->year],
            'exam_number' => ['required', 'string', 'max:30'],
            'subject'     => ['required', 'string', 'max:100'],
            'grade'       => ['required', 'string', function ($attr, $value, $fail) use ($request) {
                if (! in_array($value, ApplicantResult::gradesFor((string) $request->input('exam_type')), true)) {
                    $fail('That grade is not valid for the chosen examination.');
                }
            }],
        ]);

        $data['subject'] = Str::title(trim($data['subject']));
        $exists = $applicant->results()->where(Arr::only($data, ['exam_type', 'exam_year', 'exam_number', 'subject']))->exists();
        if ($exists) {
            return back()->withInput()->with('error', "{$data['subject']} has already been added for that examination.");
        }

        $applicant->results()->create($data);

        return back()->with('success', 'Result added.');
    }

    public function destroyResult(Request $request, ApplicantResult $result): RedirectResponse
    {
        $applicant = $this->editable($request);
        abort_unless($result->applicant_id === $applicant->id, 404);
        $result->delete();

        return back()->with('success', 'Result removed.');
    }

    public function storeDocument(Request $request): RedirectResponse
    {
        $applicant = $this->editable($request);
        $type = (string) $request->input('type');

        $request->validate([
            'type' => ['required', Rule::in(array_keys(ApplicantDocument::TYPES))],
            'file' => $type === 'passport'
                ? ['required', 'file', 'mimes:jpg,jpeg', 'mimetypes:image/jpeg', 'max:50']
                : ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ], [
            'file.max'   => $type === 'passport' ? 'The passport photograph must be a JPEG of 50 KB or less.' : 'The file must be 2 MB or less.',
            'file.mimes' => $type === 'passport' ? 'The passport photograph must be a JPEG.' : 'Upload a JPG, PNG or PDF file.',
        ]);

        $this->applications->storeDocument($applicant, $type, $request->file('file'));

        return back()->with('success', ApplicantDocument::TYPES[$type] . ' uploaded.');
    }

    public function destroyDocument(Request $request, ApplicantDocument $document): RedirectResponse
    {
        $applicant = $this->editable($request);
        abort_unless($document->applicant_id === $applicant->id, 404);
        $this->applications->deleteDocument($document);

        return back()->with('success', 'File removed.');
    }

    public function showDocument(Request $request, ApplicantDocument $document)
    {
        abort_unless($document->applicant_id === $request->attributes->get('applicant')->id, 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($document->path), ['Content-Disposition' => 'inline; filename="' . addslashes($document->original_name) . '"']);
    }

    public function submit(Request $request): RedirectResponse
    {
        $applicant = $request->attributes->get('applicant');

        try {
            $this->applications->submit($applicant);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('apply.dashboard')->with('success', 'Your application has been submitted. Print your screening slip below.');
    }

    public function slip(Request $request): View
    {
        $applicant = $request->attributes->get('applicant')->load('programme.department.faculty', 'session', 'documents');
        abort_if($applicant->status === 'draft', 403, 'Submit your application to get a screening slip.');

        return view('apply.slip', ['applicant' => $applicant]);
    }

    private function editable(Request $request): Applicant
    {
        $applicant = $request->attributes->get('applicant');
        abort_unless($this->applications->isEditable($applicant), 403, 'This application has been submitted and can no longer be changed.');

        return $applicant;
    }
}
