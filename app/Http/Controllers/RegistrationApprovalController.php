<?php

namespace App\Http\Controllers;

use App\Models\CourseAdviser;
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
    }

    /** Course advisers see (and approve) only the forms of the programmes and levels they advise. */
    private function advisees(): ?\Illuminate\Support\Collection
    {
        return auth()->user()->can('View course registrations') ? null : CourseAdviser::where('user_id', auth()->id())->get();
    }

    private function mayView(RegistrationForm $form): bool
    {
        return auth()->user()->can('View course registrations') || $this->advises($form);
    }

    private function advises(RegistrationForm $form): bool
    {
        return CourseAdviser::advises(auth()->user(), (int) $form->student->programme_id, (int) $form->level);
    }

    private function mayDecide(RegistrationForm $form): bool
    {
        return auth()->user()->can('Approve course registrations') || $this->advises($form);
    }

    public function index(Request $request): View
    {
        $mine = $this->advisees();
        abort_if($mine !== null && $mine->isEmpty(), 403);
        $status = $request->query('status', 'submitted');
        $q = trim((string) $request->query('q'));

        $forms = RegistrationForm::with('student.programme', 'session', 'semesterType')
            ->when($mine !== null, fn ($w) => $w->where(function ($x) use ($mine) {
                foreach ($mine as $a) {
                    $x->orWhere(fn ($y) => $y->where('level', $a->level)->whereHas('student', fn ($s) => $s->where('programme_id', $a->programme_id)));
                }
            }))
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($request->filled('programme_id'), fn ($w) => $w->whereHas('student', fn ($s) => $s->where('programme_id', $request->query('programme_id'))))
            ->when($q !== '', fn ($w) => $w->whereHas('student', fn ($s) => $s->where(fn ($x) => $x->where('matric_number', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%")->orWhere('first_name', 'like', "%$q%"))))
            ->latest('submitted_at')->latest('id')->paginate(25)->withQueryString();

        return view('registration.index', [
            'pagetitle'  => $mine !== null ? 'My advisees' : 'Course registrations',
            'adviser'    => $mine !== null,
            'forms'      => $forms,
            'status'     => $status,
            'counts'     => RegistrationForm::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'programmes' => Programme::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(RegistrationForm $form): View
    {
        abort_unless($this->mayView($form), 403);

        return view('registration.show', [
            'pagetitle' => 'Registration form',
            'form'      => $form->load('student.programme', 'session', 'semesterType', 'courses.course', 'decider'),
            'canDecide' => $this->mayDecide($form),
            'standing'  => $this->registration->standing($form->student),
        ]);
    }

    public function decide(Request $request, RegistrationForm $form): RedirectResponse
    {
        abort_unless($this->mayDecide($form), 403);
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
        foreach (RegistrationForm::with('student')->whereIn('id', $ids)->where('status', 'submitted')->get() as $form) {
            if (! $this->mayDecide($form)) {
                continue;
            }
            $this->registration->decide($form, true, auth()->id());
            $done++;
        }

        return back()->with($done ? 'success' : 'error', $done ? "{$done} registration(s) approved." : 'No submitted forms were selected.');
    }

    public function reopen(RegistrationForm $form): RedirectResponse
    {
        abort_unless($this->mayDecide($form), 403);
        $this->registration->reopen($form);

        return back()->with('success', 'The form is open for the student to change again.');
    }
}
