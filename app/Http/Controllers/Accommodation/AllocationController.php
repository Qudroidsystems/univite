<?php

namespace App\Http\Controllers\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AccommodationApplication;
use App\Models\BedAllocation;
use App\Models\Hostel;
use App\Models\HostelRoom;
use App\Services\Accommodation\AccommodationService;
use App\Services\Campus\PersonFinder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Applications and allocations for the current session: running the allocation, placing, moving and cancelling, check-in and check-out. */
class AllocationController extends Controller
{
    public function __construct(private AccommodationService $accommodation, private PersonFinder $people)
    {
        $this->middleware('permission:View accommodation|Allocate accommodation|Check students in and out of hostels')->only('index');
        $this->middleware('permission:Allocate accommodation')->only('run', 'place', 'move', 'cancel');
        $this->middleware('permission:Check students in and out of hostels')->only('checkIn', 'checkOut');
    }

    public function index(Request $request): View
    {
        $session = AcademicSession::current();
        abort_unless($session !== null, 404, 'Set the current session first.');
        $tab = $request->query('tab', 'allocations');
        $q = trim((string) $request->query('q'));
        $student = fn ($w) => $w->where('matric_number', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('first_name', 'like', "%{$q}%");

        return view('accommodation.allocations', [
            'pagetitle' => 'Hostel allocation', 'session' => $session, 'tab' => $tab,
            'allocations' => BedAllocation::with('student', 'room.hostel', 'invoice')->where('academic_session_id', $session->id)
                ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s), fn ($w) => $w->holding())
                ->when($request->query('hostel'), fn ($w, $h) => $w->whereHas('room', fn ($r) => $r->where('hostel_id', $h)))
                ->when($q !== '', fn ($w) => $w->whereHas('student', $student))
                ->latest('id')->paginate(40, ['*'], 'ap')->withQueryString(),
            'applications' => AccommodationApplication::with('student')->where('academic_session_id', $session->id)
                ->when($request->query('app_status', 'pending'), fn ($w, $s) => $s === 'all' ? $w : $w->where('status', $s))
                ->when($q !== '', fn ($w) => $w->whereHas('student', $student))->oldest()->paginate(40, ['*'], 'pp')->withQueryString(),
            'pending' => AccommodationApplication::where('academic_session_id', $session->id)->where('status', 'pending')->count(),
            'hostels' => Hostel::orderBy('name')->get(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $session = AcademicSession::current();
        abort_unless($session !== null, 404);
        $r = $this->accommodation->run($session, $request->user());

        return back()->with('success', $r->allocated . ' student(s) given a bed' . ($r->unsuccessful ? ', ' . $r->unsuccessful . ' could not be placed' : '') . '.');
    }

    /** Place one student by hand, in a chosen hostel (first free bed) or a chosen room and bed. */
    public function place(Request $request): RedirectResponse
    {
        $d = $request->validate(['person' => ['required', 'string', 'max:100'], 'hostel_id' => ['required', 'exists:hostels,id'], 'room' => ['nullable', 'string', 'max:60'],
            'bed' => ['nullable', 'integer', 'min:1', 'max:50'], 'override' => ['nullable', 'boolean']]);
        $session = AcademicSession::current();
        $student = $this->people->find($d['person'])?->student;
        if (! $student || ! $session) {
            return back()->withInput()->with('error', 'No student found with that matric number.');
        }
        $hostel = Hostel::findOrFail($d['hostel_id']);
        try {
            if (! empty($d['room'])) {
                $room = $this->room($hostel, $d['room']);
                $bed = $d['bed'] ?? null;
            } else {
                [$room, $bed] = $this->accommodation->freeBed($hostel, $session) ?? throw new RuntimeException($hostel->name . ' is full.');
            }
            $a = $this->accommodation->allocate($student, $session, $room, $bed, $request->user(), null, ! ($d['override'] ?? false));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $student->full_name . ' placed in ' . $a->place() . '.');
    }

    public function move(Request $request, BedAllocation $allocation): RedirectResponse
    {
        $d = $request->validate(['hostel_id' => ['required', 'exists:hostels,id'], 'room' => ['required', 'string', 'max:60'], 'bed' => ['nullable', 'integer', 'min:1', 'max:50'], 'reason' => ['required', 'string', 'max:255']]);
        try {
            $this->accommodation->move($allocation, $this->room(Hostel::findOrFail($d['hostel_id']), $d['room']), $d['bed'] ?? null, $request->user(), $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Moved to ' . $allocation->fresh()->place() . '.');
    }

    public function cancel(Request $request, BedAllocation $allocation): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $this->accommodation->cancel($allocation, $request->user(), $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Allocation cancelled; the bed is free.');
    }

    public function checkIn(Request $request, BedAllocation $allocation): RedirectResponse
    {
        try {
            $this->accommodation->checkIn($allocation, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $allocation->student->full_name . ' checked in.');
    }

    public function checkOut(Request $request, BedAllocation $allocation): RedirectResponse
    {
        try {
            $this->accommodation->checkOut($allocation, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $allocation->student->full_name . ' checked out.');
    }

    /** A room typed as its number, or as "Block Number" when the hostel has blocks. */
    private function room(Hostel $hostel, string $typed): HostelRoom
    {
        $typed = trim(preg_replace('/^room\s+/i', '', trim($typed)));
        $rooms = $hostel->rooms()->get();
        $match = $rooms->filter(fn ($r) => strcasecmp($r->name, $typed) === 0 || strcasecmp(trim($r->block . ' ' . $r->name), $typed) === 0 || strcasecmp($r->label(), $typed) === 0);

        return match ($match->count()) {
            1 => $match->first(),
            0 => throw new RuntimeException($hostel->name . ' has no room "' . $typed . '".'),
            default => throw new RuntimeException('More than one block in ' . $hostel->name . ' has room "' . $typed . '". Type the block too, for example "' . $match->first()->block . ' ' . $typed . '".'),
        };
    }
}
