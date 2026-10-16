<?php

namespace App\Http\Controllers;

use App\Data\Results\CarryoverWaiverRequest;
use App\Models\CarryoverWaiver;
use App\Models\Department;
use App\Services\Access\ScopedAccess;
use App\Services\Results\CarryoverWaivers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Carry-over waivers: the list (for the departments the person looks after), granting one and withdrawing one. */
class CarryoverWaiverController extends Controller
{
    public function __construct(private CarryoverWaivers $waivers, private ScopedAccess $access)
    {
    }

    public function index(Request $request): View
    {
        $scope = $this->access->scope($request->user(), 'Waive carryover courses');
        $view = $this->access->scope($request->user(), 'View student results');
        abort_if($scope->isEmpty() && $view->isEmpty(), 403);
        $show = $request->query('show', 'active');

        $waivers = CarryoverWaiver::with('student.programme.department', 'course', 'granter', 'revoker')
            ->whereHas('student', fn ($q) => $scope->all || $view->all ? $q : $q->whereHas('programme', fn ($p) => $p->whereIn('department_id', array_merge($scope->departmentIds, $view->departmentIds))))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('student.programme', fn ($p) => $p->where('department_id', (int) $request->query('department_id'))))
            ->when($show === 'active', fn ($q) => $q->active())
            ->when($show === 'withdrawn', fn ($q) => $q->whereNotNull('revoked_at'))
            ->latest('id')->paginate(30)->withQueryString();

        $departments = Department::orderBy('name')->get()->filter(fn ($d) => $scope->coversDepartment($d->id) || $view->coversDepartment($d->id));

        return view('results.waivers', [
            'pagetitle' => 'Carry-over waivers', 'waivers' => $waivers, 'show' => $show,
            'departments' => $departments->pluck('name', 'id'), 'department' => $request->query('department_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $w = $this->waivers->grant(CarryoverWaiverRequest::fromRequest($request), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$w->course->code} waived for {$w->student->full_name}.");
    }

    public function withdraw(Request $request, CarryoverWaiver $waiver): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->waivers->revoke($waiver, $d['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "The waiver on {$waiver->course->code} for {$waiver->student->full_name} was withdrawn. It is a carry-over again.");
    }
}
