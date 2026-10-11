<?php

namespace App\Http\Controllers;

use App\Models\StaffUnavailability;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** When lecturers and invigilators cannot be scheduled. Staff can always record their own; "Set staff availability" records anyone's. */
class StaffAvailabilityController extends Controller
{
    public function index(Request $request): View
    {
        $all = $request->user()->can('Set staff availability');
        abort_if(! $all && $request->user()->student, 403);

        return view('timetables.availability', [
            'pagetitle' => 'Staff availability', 'all' => $all,
            'rows' => StaffUnavailability::with('user')->when(! $all, fn ($q) => $q->where('user_id', $request->user()->id))
                ->when($all && $request->filled('user'), fn ($q) => $q->where('user_id', $request->user))->orderBy('user_id')->orderBy('day')->orderBy('date_from')->paginate(50)->withQueryString(),
            'staff' => $all ? User::whereDoesntHave('student')->where('is_disabled', false)->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'], 'kind' => ['required', 'in:weekly,dates'],
            'day' => ['required_if:kind,weekly', 'nullable', 'integer', 'between:1,7'],
            'date_from' => ['required_if:kind,dates', 'nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'], 'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at', 'required_with:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user()->can('Set staff availability') && ! empty($d['user_id']) ? (int) $d['user_id'] : $request->user()->id;
        abort_if($request->user()->student, 403);
        StaffUnavailability::create([
            'user_id' => $user, 'day' => $d['kind'] === 'weekly' ? $d['day'] : null, 'date_from' => $d['kind'] === 'dates' ? $d['date_from'] : null,
            'date_to' => $d['kind'] === 'dates' ? ($d['date_to'] ?? $d['date_from']) : null, 'starts_at' => $d['starts_at'] ?? null, 'ends_at' => $d['ends_at'] ?? null,
            'reason' => $d['reason'] ?? null, 'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Saved. Timetables generated from now on will not schedule this person then; existing timetables are re-checked when they are next opened or generated.');
    }

    public function destroy(Request $request, StaffUnavailability $row): RedirectResponse
    {
        abort_unless($row->user_id === $request->user()->id || $request->user()->can('Set staff availability'), 403);
        $row->delete();

        return back()->with('success', 'Removed.');
    }
}
