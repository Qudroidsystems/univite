<?php

namespace App\Http\Controllers;

use App\Models\Programme;
use App\Models\RegistrationForm;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Staff view of course-registration forms: review, approve, return, reopen, print. */
class RegistrationApprovalController extends Controller
{
    public function __construct(private RegistrationService $registration)
    {
        $this->middleware('permission:View course registrations')->only(['index', 'show']);
        $this->middleware('permission:Approve course registrations')->only(['decide', 'bulk', 'reopen']);
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', 'submitted');
        $q = trim((string) $request->query('q'));

        $forms = RegistrationForm::with('student.programme', 'session', 'semesterType')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($request->filled('programme_id'), fn ($w) => $w->whereHas('student', fn ($s) => $s->where('programme_id', $request->query('programme_id'))))
            ->when($q !== '', fn ($w) => $w->whereHas('student', fn ($s) => $s->where(fn ($x) => $x->where('matric_number', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%")->orWhere('first_name', 'like', "%$q%"))))
            ->latest('submitted_at')->latest('id')->paginate(25)->withQueryString();

        return view('registration.index', [
            'pagetitle'  => 'Course registrations',
            'forms'      => $forms,
            'status'     => $status,
            'counts'     => RegistrationForm::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'programmes' => Programme::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(RegistrationForm $form): View
    {
        return view('registration.show', [
            'pagetitle' => 'Registration form',
            'form'      => $form->load('student.programme', 'session', 'semesterType', 'courses.course', 'decider'),
        ]);
    }

    public function decide(Request $request, RegistrationForm $form): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,return'], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->registration->decide($form, $data['decision'] === 'approve', auth()->id(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['decision'] === 'approve' ? 'Registration approved.' : 'Registration returned to the student.');
    }

    /** Approve every ticked submitted form. */
    public function bulk(Request $request): RedirectResponse
    {
        $ids = (array) $request->validate(['forms' => ['required', 'array'], 'forms.*' => ['integer']])['forms'];
        $done = 0;
        foreach (RegistrationForm::whereIn('id', $ids)->where('status', 'submitted')->get() as $form) {
            $this->registration->decide($form, true, auth()->id());
            $done++;
        }

        return back()->with($done ? 'success' : 'error', $done ? "{$done} registration(s) approved." : 'No submitted forms were selected.');
    }

    public function reopen(RegistrationForm $form): RedirectResponse
    {
        $this->registration->reopen($form);

        return back()->with('success', 'The form is open for the student to change again.');
    }
}
