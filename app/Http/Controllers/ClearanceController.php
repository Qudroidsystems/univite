<?php

namespace App\Http\Controllers;

use App\Models\ClearanceItem;
use App\Models\Student;
use App\Services\ClearanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Staff side of clearance: who is still being cleared, and the sign-off of each item. */
class ClearanceController extends Controller
{
    public function __construct(private ClearanceService $clearance)
    {
        $this->middleware('permission:View clearance')->only(['index', 'show', 'slip']);
        $this->middleware('permission:Clear students')->only(['decide', 'reset', 'bulk']);
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $tab = in_array($request->query('tab'), ['pending', 'cleared'], true) ? $request->query('tab') : 'pending';
        $itemId = $request->integer('item') ?: null;

        $base = Student::query()->where('needs_onboarding', true)->whereNotNull('verified_at')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('matric_number', 'like', "%{$q}%")
                ->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")))
            ->when($request->filled('batch'), fn ($query) => $query->where('admission_batch', $request->query('batch')));

        $counts = [
            'pending' => (clone $base)->whereNull('cleared_at')->count(),
            'cleared' => (clone $base)->whereNotNull('cleared_at')->count(),
        ];

        $students = $base->with(['programme', 'clearances'])
            ->when($tab === 'cleared', fn ($query) => $query->whereNotNull('cleared_at'), fn ($query) => $query->whereNull('cleared_at'))
            ->when($itemId, fn ($query) => $query->whereHas('clearances', fn ($c) => $c->where('clearance_item_id', $itemId)->where('status', '!=', 'cleared')))
            ->orderBy('last_name')->paginate(25)->withQueryString();

        $items = $this->clearance->items();

        return view('clearance.index', [
            'pagetitle' => 'Clearance',
            'students'  => $students,
            'items'     => $items,
            'counts'    => $counts,
            'tab'       => $tab,
            'itemId'    => $itemId,
            'service'   => $this->clearance,
            'batches'   => Student::where('needs_onboarding', true)->whereNotNull('admission_batch')->distinct()->orderBy('admission_batch')->pluck('admission_batch'),
            'signable'  => $items->filter(fn ($i) => $this->clearance->canSign(auth()->user(), $i))->values(),
        ]);
    }

    public function show($id): View
    {
        $student = Student::with(['programme', 'user'])->findOrFail($id);
        abort_unless($student->needs_onboarding, 404);

        return view('clearance.show', [
            'pagetitle' => $student->full_name,
            'student'   => $student,
            'rows'      => $this->clearance->checklist($student),
            'service'   => $this->clearance,
        ]);
    }

    public function decide(Request $request, $id, ClearanceItem $item): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:cleared,rejected'], 'note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->clearance->decide(Student::findOrFail($id), $item, $data['status'], $data['note'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['status'] === 'cleared' ? "{$item->name} cleared." : "{$item->name} rejected. The student has been told why.");
    }

    public function reset(Request $request, $id, ClearanceItem $item): RedirectResponse
    {
        try {
            $this->clearance->reset(Student::findOrFail($id), $item, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$item->name} is back to pending.");
    }

    /** Clear one manual item for many students at once. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item_id'       => ['required', 'exists:clearance_items,id'],
            'student_ids'   => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'exists:students,id'],
        ], ['student_ids.required' => 'Tick at least one student.']);

        $item = ClearanceItem::findOrFail($data['item_id']);
        $done = 0;
        $failed = [];

        foreach (Student::whereIn('id', $data['student_ids'])->get() as $student) {
            try {
                $this->clearance->decide($student, $item, 'cleared', null, $request->user());
                $done++;
            } catch (RuntimeException $e) {
                $failed[$e->getMessage()] = true;
            }
        }

        if (! $done && $failed) {
            return back()->with('error', implode(' ', array_keys($failed)));
        }

        return back()->with('success', "{$item->name} cleared for {$done} student(s)." . ($failed ? ' Some could not be cleared: ' . implode(' ', array_keys($failed)) : ''));
    }

    public function slip($id): View
    {
        $student = Student::with(['programme.department.faculty', 'entrySession'])->findOrFail($id);
        abort_unless($student->cleared_at, 404, 'This student is not fully cleared yet.');

        return view('clearance.slip', ['pagetitle' => 'Clearance slip', 'student' => $student, 'rows' => $this->clearance->checklist($student)]);
    }
}
