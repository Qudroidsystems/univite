<?php

namespace App\Http\Controllers\Accommodation;

use App\Data\Accommodation\HostelInput;
use App\Data\Accommodation\RoomBatch;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\BedAllocation;
use App\Models\Hostel;
use App\Models\HostelRoom;
use App\Services\Accommodation\AccommodationService;
use App\Services\Accommodation\AccommodationSettings;
use App\Services\Campus\PersonFinder;
use App\Services\OperationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Hostels, their rooms and bed rates, and the accommodation rules. */
class HostelController extends Controller
{
    public function __construct(
        private AccommodationService $accommodation,
        private AccommodationSettings $settings,
        private PersonFinder $people,
        private OperationAudit $audit,
    ) {
        $this->middleware('permission:View accommodation|Set accommodation policy|Allocate accommodation|Check students in and out of hostels')->only('index', 'show');
        $this->middleware('permission:Set accommodation policy')->except('index', 'show');
    }

    public function index(): View
    {
        $session = AcademicSession::current();

        return view('accommodation.hostels', ['pagetitle' => 'Hostels', 'session' => $session, 'rows' => $session ? $this->accommodation->occupancy($session) : collect()]);
    }

    public function create(): View
    {
        return view('accommodation.hostel-form', ['pagetitle' => 'New hostel', 'hostel' => new Hostel(['gender' => 'mixed', 'is_active' => true]), 'session' => AcademicSession::current()]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$in, $error] = $this->input($request);
        if ($error) {
            return back()->withInput()->with('error', $error);
        }
        $hostel = $this->accommodation->saveHostel(null, $in, AcademicSession::current());

        return redirect()->route('hostels.show', $hostel)->with('success', 'Hostel added. Now add its rooms.');
    }

    public function show(Hostel $hostel): View
    {
        $session = AcademicSession::current();
        $hostel->load('rates', 'warden');
        $allocations = $session ? BedAllocation::with('student')->whereIn('hostel_room_id', $hostel->rooms()->pluck('id'))->where('academic_session_id', $session->id)->holding()->get()->groupBy('hostel_room_id') : collect();

        return view('accommodation.hostel', ['pagetitle' => $hostel->name, 'hostel' => $hostel, 'rooms' => $hostel->rooms()->get(), 'allocations' => $allocations, 'session' => $session]);
    }

    public function edit(Hostel $hostel): View
    {
        return view('accommodation.hostel-form', ['pagetitle' => 'Hostel details', 'hostel' => $hostel->load('rates', 'warden'), 'session' => AcademicSession::current()]);
    }

    public function update(Request $request, Hostel $hostel): RedirectResponse
    {
        [$in, $error] = $this->input($request);
        if ($error) {
            return back()->withInput()->with('error', $error);
        }
        $this->accommodation->saveHostel($hostel, $in, AcademicSession::current());

        return redirect()->route('hostels.show', $hostel)->with('success', 'Saved.');
    }

    public function destroy(Hostel $hostel): RedirectResponse
    {
        if ($hostel->allocations()->exists()) {
            return back()->with('error', 'Students have been placed in this hostel, so it is kept. Switch it off instead.');
        }
        $hostel->delete();

        return redirect()->route('hostels.index')->with('success', 'Hostel removed.');
    }

    public function addRooms(Request $request, Hostel $hostel): RedirectResponse
    {
        $n = $this->accommodation->addRooms($hostel, RoomBatch::fromArray($request->validate(RoomBatch::rules())));

        return back()->with('success', $n . ' room(s) added.');
    }

    public function updateRoom(Request $request, HostelRoom $room): RedirectResponse
    {
        $d = $request->validate(['capacity' => ['required', 'integer', 'min:1', 'max:50'], 'is_active' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $session = AcademicSession::current();
        $highest = $session ? (int) BedAllocation::where('hostel_room_id', $room->id)->where('academic_session_id', $session->id)->holding()->max('bed') : 0;
        if ($d['capacity'] < $highest) {
            return back()->with('error', $room->label() . ' has someone in bed ' . $highest . '. Move them before reducing the beds.');
        }
        $room->update(['capacity' => $d['capacity'], 'is_active' => (bool) ($d['is_active'] ?? false), 'note' => $d['note'] ?? null]);

        return back()->with('success', $room->label() . ' saved.');
    }

    public function destroyRoom(HostelRoom $room): RedirectResponse
    {
        if ($room->allocations()->exists()) {
            return back()->with('error', 'Students have stayed in ' . $room->label() . ', so it is kept. Switch it off instead.');
        }
        $room->delete();

        return back()->with('success', 'Room removed.');
    }

    public function settings(): View
    {
        return view('accommodation.settings', ['pagetitle' => 'Accommodation rules', 'group' => $this->settings, 'values' => $this->settings->all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        if ($changed) {
            $this->audit->note('accommodation_settings', 'accommodation', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Saved.');
    }

    /** @return array{0: ?HostelInput, 1: ?string} */
    private function input(Request $request): array
    {
        $d = $request->validate(HostelInput::rules());
        $wardenId = null;
        if (! empty($d['warden'])) {
            $warden = $this->people->find($d['warden']);
            if (! $warden || ! $warden->staffProfile) {
                return [null, 'The warden must be a member of staff. Type their staff number or email.'];
            }
            $wardenId = $warden->id;
        }

        return [HostelInput::fromArray($d, $wardenId), null];
    }
}
