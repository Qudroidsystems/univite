@extends('layouts.master')

@section('content')
@php $v = $venue; $u = auth()->user(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $v->name, 'icon' => 'ri-building-2-line', 'subtitle' => $v->code . ' · ' . $v->type_label . ($v->location ? ' · ' . $v->location : ''), 'back' => [route('venues.index'), 'Venues']])
    @include('academics.partials.alerts')

    @if ($blocked)
        <div class="alert alert-warning"><i class="ri-forbid-line me-1"></i>Not available for new allocations today: {{ $blocked }}.</div>
    @endif

    <div class="row">
        <div class="col-xl-4">
            <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Profile</h6>@can('Edit venues')<a href="{{ route('venues.edit', $v) }}" class="btn btn-sm btn-soft-primary"><i class="ri-pencil-line me-1"></i>Edit</a>@endcan</div>
                <div class="card-body"><table class="table table-sm table-borderless mb-0 fs-13">
                    <tr><th class="text-muted fw-normal" style="width:45%">Status</th><td><span class="badge {{ \App\Models\Venue::BADGES[$v->status] }}">{{ \App\Models\Venue::STATUSES[$v->status] }}</span> @unless ($v->is_active)<span class="badge bg-light text-muted">Inactive</span>@endunless</td></tr>
                    <tr><th class="text-muted fw-normal">Installed seats</th><td>{{ number_format($v->capacity) }}</td></tr>
                    <tr><th class="text-muted fw-normal">Usable for lectures</th><td>{{ number_format($v->teaching_capacity) }}</td></tr>
                    <tr><th class="text-muted fw-normal">Usable for examinations</th><td>{{ number_format($v->exam_capacity) }} @if ($v->allows('exam'))<span class="text-muted">· {{ $v->shared_exams ? 'papers may share' : 'one paper at a time' }}</span>@endif</td></tr>
                    <tr><th class="text-muted fw-normal">Used for</th><td>{{ collect($v->uses ?: array_keys(\App\Models\Venue::USES))->map(fn ($x) => \App\Models\Venue::USES[$x] ?? $x)->implode(', ') }}</td></tr>
                    <tr><th class="text-muted fw-normal">Facilities</th><td>{{ collect($v->facilities)->map(fn ($f) => \App\Support\Lookups::label('venue_facility', $f))->implode(', ') ?: 'None recorded' }}</td></tr>
                    <tr><th class="text-muted fw-normal">Accessibility</th><td>{{ $v->wheelchair_access ? 'Wheelchair access' : 'No wheelchair access recorded' }}@if ($v->accessible_seats) · {{ count($v->accessible_seats) }} accessible seat(s)@endif</td></tr>
                    @if ($v->department)<tr><th class="text-muted fw-normal">Department</th><td>{{ $v->department->name }}</td></tr>@endif
                    @if ($v->entry_info)<tr><th class="text-muted fw-normal">Hall entry</th><td>{{ $v->entry_info }}</td></tr>@endif
                    <tr><th class="text-muted fw-normal">Map position</th><td>@if ($v->latitude && $v->longitude)<a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $v->latitude }},{{ $v->longitude }}">{{ $v->latitude }}, {{ $v->longitude }} <i class="ri-external-link-line"></i></a>@else<span class="text-muted">Not set. Edit the venue to add it, so students see how far it is.</span>@endif</td></tr>
                    @if ($v->notes)<tr><th class="text-muted fw-normal">Notes</th><td>{{ $v->notes }}</td></tr>@endif
                </table></div></div>

            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Closures</h6><div class="text-muted fs-12">Dates the venue cannot be used. Timetables skip it and list what has to move.</div></div>
                <ul class="list-group list-group-flush">
                    @forelse ($v->closures->sortByDesc('starts_on') as $c)
                        <li class="list-group-item d-flex justify-content-between gap-2 {{ $c->lifted_at ? 'text-muted' : '' }}"><div><strong>{{ $c->starts_on->format('j M Y') }} – {{ $c->ends_on->format('j M Y') }}</strong><div class="fs-12">{{ $c->reason }} @if ($c->lifted_at)· lifted {{ $c->lifted_at->format('j M') }}@endif</div></div>
                            @if (! $c->lifted_at && $c->ends_on->gte(today()))@can('Set venue closures')<form method="POST" action="{{ route('venues.closures.lift', [$v, $c]) }}">@csrf<button class="btn btn-sm btn-light">Lift</button></form>@endcan @endif</li>
                    @empty
                        <li class="list-group-item text-muted">No closures.</li>
                    @endforelse
                </ul>
                @can('Set venue closures')
                    <div class="card-body border-top"><form method="POST" action="{{ route('venues.closures.store', $v) }}" class="row g-2">@csrf
                        <div class="col-6"><label class="form-label fs-12">From</label><input type="date" name="starts_on" class="form-control form-control-sm" required></div>
                        <div class="col-6"><label class="form-label fs-12">To</label><input type="date" name="ends_on" class="form-control form-control-sm" required></div>
                        <div class="col-12"><input name="reason" class="form-control form-control-sm" placeholder="Reason, e.g. roof repairs" required minlength="5"></div>
                        <div class="col-12"><button class="btn btn-sm btn-warning">Close the venue for these dates</button></div></form></div>
                @endcan
            </div>
        </div>

        <div class="col-xl-8">
            @if ($impact->isNotEmpty())
                <div class="card border-warning"><div class="card-header bg-warning-subtle"><h6 class="card-title mb-0"><i class="ri-alert-line me-1"></i>Affected timetable entries</h6><div class="fs-12">These use the venue while it is out of use. The committee can move each one; the suggested rooms are free at the same time and big enough.</div></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Timetable</th><th>Class / paper</th><th>When</th><th>Suggested replacement</th></tr></thead><tbody>
                        @foreach ($impact as $row)
                            <tr><td><a href="{{ route('timetables.show', $row['timetable']) }}">{{ $row['timetable']->title }}</a></td><td>{{ $row['entry']->item?->label }}</td><td class="fs-13">{{ $row['entry']->when }}</td>
                                <td class="fs-13">{{ implode(' · ', $row['options']) ?: 'No free venue at that time: move it to another time.' }} <a href="{{ route('timetables.entry', [$row['timetable'], $row['entry']->unit_key]) }}" class="ms-1">Move</a></td></tr>
                        @endforeach
                    </tbody></table></div></div>
            @endif

            @if ($v->allows('exam') && $v->exam_capacity)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Examination seat map</h6><div class="text-muted fs-12">{{ count($map) }} usable seats. Front of the hall at the top. <span class="badge bg-danger-subtle text-danger">blocked</span> <span class="badge bg-info-subtle text-info">accessible</span></div></div>
                    <div class="card-body" style="overflow-x:auto">
                        @php $rows = $v->seat_rows ?: (int) ceil(max(1, $v->exam_capacity) / ($v->seats_per_row ?: 10)); $cols = $v->seats_per_row ?: 10; $usable = collect($map)->keyBy('label'); @endphp
                        <div class="text-center text-muted fs-11 mb-2 border rounded py-1">FRONT · invigilators</div>
                        <table class="mx-auto" style="border-collapse:separate;border-spacing:3px">
                            @for ($r = 1; $r <= $rows; $r++)
                                <tr><th class="text-muted fs-11 pe-1">R{{ sprintf('%02d', $r) }}</th>
                                @for ($c = 1; $c <= $cols; $c++)
                                    @php $label = \App\Models\Venue::seatLabel($r, $c); $seat = $usable[$label] ?? null; @endphp
                                    <td title="{{ $label }}" class="text-center fs-10 rounded {{ ! $seat ? (in_array($label, $v->blocked_seats ?? [], true) ? 'bg-danger-subtle text-danger' : 'bg-light text-muted') : ($seat['accessible'] ? 'bg-info-subtle text-info' : 'bg-success-subtle text-success') }}" style="width:26px;height:22px">{{ $c }}</td>
                                @endfor</tr>
                            @endfor
                        </table>
                    </div></div>
            @endif

            <div class="card"><div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Faults, inspections and incidents</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($v->issues->sortByDesc('id') as $i)
                        <li class="list-group-item"><div class="d-flex justify-content-between gap-2 flex-wrap">
                            <div><strong>{{ $i->title }}</strong> <span class="badge {{ $i->severity === 'blocking' ? 'bg-danger' : ($i->severity === 'major' ? 'bg-warning' : 'bg-secondary') }}">{{ \App\Models\VenueIssue::SEVERITIES[$i->severity] }}</span>
                                <span class="badge {{ $i->status === 'resolved' ? 'bg-success-subtle text-success' : 'bg-light text-body border' }}">{{ \App\Models\VenueIssue::STATUSES[$i->status] }}</span>
                                <div class="fs-12 text-muted">Reported by {{ $i->reporter?->name ?? 'someone' }}, {{ $i->created_at->format('j M Y') }}@if ($i->inspected_on) · inspected {{ $i->inspected_on->format('j M Y') }}@endif</div>
                                @if ($i->details)<div class="fs-13">{{ $i->details }}</div>@endif
                                @if ($i->resolution)<div class="fs-13 text-success">Resolved by {{ $i->resolver?->name }}: {{ $i->resolution }}</div>@endif</div>
                            @if ($i->status !== 'resolved')@can('Clear venue faults')
                                <form method="POST" action="{{ route('venues.issues.resolve', [$v, $i]) }}" class="d-flex gap-1 align-items-start">@csrf
                                    <input name="resolution" class="form-control form-control-sm" placeholder="What was done" style="width:200px">
                                    <button name="status" value="in_progress" class="btn btn-sm btn-light">Being fixed</button><button name="status" value="resolved" class="btn btn-sm btn-success">Resolved</button></form>
                            @endcan @endif
                        </div></li>
                    @empty
                        <li class="list-group-item text-muted">Nothing reported.</li>
                    @endforelse
                </ul>
                @can('Record venue faults')
                    <div class="card-body border-top"><form method="POST" action="{{ route('venues.issues.store', $v) }}" class="row g-2">@csrf
                        <div class="col-md-5"><input name="title" class="form-control form-control-sm" placeholder="What is wrong, e.g. projector not working" required></div>
                        <div class="col-md-3"><select name="severity" class="form-select form-select-sm">@foreach (\App\Models\VenueIssue::SEVERITIES as $k => $s)<option value="{{ $k }}">{{ $s }}</option>@endforeach</select></div>
                        <div class="col-md-2"><input type="date" name="inspected_on" class="form-control form-control-sm" title="Inspection date (optional)"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-soft-danger w-100">Report</button></div>
                        <div class="col-12"><textarea name="details" rows="1" class="form-control form-control-sm" placeholder="Details (optional)"></textarea></div></form></div>
                @endcan
            </div>

            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Bookings</h6><div class="text-muted fs-12">Meetings and events outside the timetables. A booking is refused if the venue is closed or a published timetable uses it then.</div></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
                    @forelse ($bookings as $b)
                        <tr><td>{{ $b->date->format('D j M Y') }}, {{ substr($b->starts_at, 0, 5) }}–{{ substr($b->ends_at, 0, 5) }}</td><td>{{ $b->title }}@if ($b->attendees) <span class="text-muted">· {{ $b->attendees }} people</span>@endif</td><td class="text-muted fs-12">{{ $b->booker?->name }}</td>
                            <td class="text-end">@if ($b->booked_by === $u->id || $u->can('Cancel venue bookings'))<form method="POST" action="{{ route('venues.bookings.cancel', [$v, $b]) }}">@csrf<button class="btn btn-sm btn-light">Cancel</button></form>@endif</td></tr>
                    @empty
                        <tr><td class="text-muted">No upcoming bookings.</td></tr>
                    @endforelse
                </tbody></table></div>
                @can('Create venue bookings')
                    <div class="card-body border-top"><form method="POST" action="{{ route('venues.bookings.store', $v) }}" class="row g-2">@csrf
                        <div class="col-md-4"><input name="title" class="form-control form-control-sm" placeholder="What for" required value="{{ old('title') }}"></div>
                        <div class="col-md-2"><input type="date" name="date" class="form-control form-control-sm" required value="{{ old('date') }}"></div>
                        <div class="col-md-2"><input type="time" name="starts_at" class="form-control form-control-sm" required value="{{ old('starts_at') }}"></div>
                        <div class="col-md-2"><input type="time" name="ends_at" class="form-control form-control-sm" required value="{{ old('ends_at') }}"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Book</button></div></form></div>
                @endcan
            </div>

            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Timetabled use</h6></div>
                <div class="card-body">
                    @forelse ($usage as $rows)
                        @php $t = $rows->first()->timetable; @endphp
                        <div class="mb-3"><a href="{{ route('timetables.show', $t) }}" class="fw-semibold">{{ $t->title }}</a> <span class="badge {{ \App\Models\Timetable::BADGES[$t->status] }}">{{ \App\Models\Timetable::STATUSES[$t->status] }}</span>
                            <div class="d-flex flex-wrap gap-1 mt-1">@foreach ($rows->take(60) as $e)<span class="badge bg-light text-body border fw-normal">{{ $e->course?->code }} · {{ $e->when }}</span>@endforeach @if ($rows->count() > 60)<span class="text-muted fs-12">and {{ $rows->count() - 60 }} more</span>@endif</div></div>
                    @empty
                        <p class="text-muted mb-0">No timetable uses this venue yet.</p>
                    @endforelse
                </div></div>
        </div>
    </div>
</div></div></div>
@endsection
