<?php

namespace App\Http\Controllers;

use App\Data\Access\AccessScope;
use App\Models\CourseAdviser;
use App\Models\Programme;
use App\Models\RegistrationForm;
use App\Services\Access\ScopedAccess;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/** Staff view of course-registration forms: review, approve, return, reopen, print. */
class RegistrationApprovalController extends Controller
{
    public function __construct(private RegistrationService $registration, private ScopedAccess $access)
    {
    }

    /**
     * Who sees which forms: anyone with the permission sees all; a head (scoped role) sees their department's or
     * faculty's students; a course adviser sees the programmes and levels they advise. A person can be both.
     */
    private function viewScope(): AccessScope
    {
        return $this->access->scope(auth()->user(), 'View course registrations');
    }

    private function advisees(): Collection
    {
        return CourseAdviser::where('user_id', auth()->id())->get();
    }

    private function mayView(RegistrationForm $form): bool
    {
        return $this->viewScope()->coversStudent($form->student) || $this->advises($form);
    }

    private function advises(RegistrationForm $form): bool
    {
        return CourseAdviser::advises(auth()->user(), (int) $form->student->programme_id, (int) $form->level);
    }

    private function mayDecide(RegistrationForm $form): bool
    {
        return $this->access->scope(auth()->user(), 'Approve course registrations')->coversStudent($form->student) || $this->advises($form);
    }

    public function index(Request $request): View
    {
        $scope = $this->viewScope();
        $mine = $scope->all ? collect() : $this->advisees();
        abort_if(! $scope->all && $scope->isEmpty() && $mine->isEmpty(), 403);
        $status = $request->query('status', 'submitted');
        $q = trim((string) $request->query('q'));

        $limit = function ($w) use ($scope, $mine) {
            $w->where(function ($x) use ($scope, $mine) {
                if (! $scope->isEmpty()) {
                    $x->orWhere(fn ($y) => $scope->students($y, 'student'));
                }
                foreach ($mine as $a) {
                    $x->orWhere(fn ($y) => $y->where('level', $a->level)->whereHas('student', fn ($s) => $s->where('programme_id', $a->programme_id)));
                }
            });
        };
        $forms = RegistrationForm::with('student.programme', 'session', 'semesterType')
            ->when(! $scope->all, $limit)
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($request->filled('programme_id'), fn ($w) => $w->whereHas('student', fn ($s) => $s->where('programme_id', $request->query('programme_id'))))
            ->when($q !== '', fn ($w) => $w->whereHas('student', fn ($s) => $s->where(fn ($x) => $x->where('matric_number', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%")->orWhere('first_name', 'like', "%$q%"))))
            ->latest('submitted_at')->latest('id')->paginate(25)->withQueryString();
        $approve = $this->access->scope(auth()->user(), 'Approve course registrations');

        return view('registration.index', [
            'pagetitle'  => $scope->all ? 'Course registrations' : ($scope->isEmpty() ? 'My advisees' : 'Course registrations (my department)'),
            'adviser'    => ! $scope->all,
            'canApprove' => $mine->isNotEmpty() || ! $approve->isEmpty(),
            'forms'      => $forms,
            'status'     => $status,
            'counts'     => RegistrationForm::selectRaw('status, count(*) c')->when(! $scope->all, $limit)->groupBy('status')->pluck('c', 'status'),
            'programmes' => Programme::when(! $scope->all && $mine->isEmpty(), fn ($p) => $p->whereIn('department_id', $scope->departmentIds))->orderBy('name')->pluck('name', 'id'),
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
