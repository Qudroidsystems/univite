@extends('layouts.master')

@section('content')
@php $u = auth()->user(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Hostel allocation', 'icon' => 'ri-hotel-bed-line', 'subtitle' => $session->name, 'back' => [route('hostels.index'), 'Hostels']])
    @include('academics.partials.alerts')
    @can('Allocate accommodation')
    <div class="row">
        <div class="col-lg-4"><div class="card"><div class="card-body">
            <h6>Applications waiting: {{ $pending }}</h6>
            <p class="fs-13 text-muted">Give every waiting applicant a bed in the order set under Rules, starting with their first choice.</p>
            <form method="POST" action="{{ route('accommodation.run') }}" onsubmit="return confirm('Place all {{ $pending }} waiting applicant(s) now?')">@csrf<button class="btn btn-primary" @disabled(! $pending)>Allocate beds</button></form>
        </div></div></div>
        <div class="col-lg-8"><div class="card"><div class="card-body">
            <h6>Place a student</h6>
            <form method="POST" action="{{ route('accommodation.place') }}" class="row g-2">@csrf
                <div class="col-md-4"><input name="person" value="{{ old('person') }}" class="form-control" placeholder="Matric number" required></div>
                <div class="col-md-4"><select name="hostel_id" data-lookup="hostels" class="form-select" required>@foreach ($hostels as $h)<option value="{{ $h->id }}">{{ $h->name }}</option>@endforeach</select></div>
                <div class="col-md-2"><input name="room" value="{{ old('room') }}" class="form-control" placeholder="Room (optional)" title="Leave empty for the first free bed"></div>
                <div class="col-md-1"><input name="bed" value="{{ old('bed') }}" type="number" min="1" class="form-control" placeholder="Bed"></div>
                <div class="col-md-1"><button class="btn btn-light w-100">Place</button></div>
                <div class="col-12"><div class="form-check"><input type="checkbox" name="override" value="1" class="form-check-input" id="ov"><label class="form-check-label fs-13" for="ov">Place even if the hostel is meant for other students</label></div></div>
            </form>
        </div></div></div>
    </div>
    @endcan

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
            <ul class="nav nav-pills">
                <li class="nav-item"><a class="nav-link py-1 {{ $tab === 'allocations' ? 'active' : '' }}" href="{{ route('accommodation.allocations', ['tab' => 'allocations']) }}">Beds given</a></li>
                <li class="nav-item"><a class="nav-link py-1 {{ $tab === 'applications' ? 'active' : '' }}" href="{{ route('accommodation.allocations', ['tab' => 'applications']) }}">Applications @if ($pending)<span class="badge bg-warning">{{ $pending }}</span>@endif</a></li>
            </ul>
            <form method="GET" class="d-flex gap-2"><input type="hidden" name="tab" value="{{ $tab }}">
                @if ($tab === 'allocations')
                    <select name="hostel" class="form-select form-select-sm"><option value="">All hostels</option>@foreach ($hostels as $h)<option value="{{ $h->id }}" @selected(request('hostel') == $h->id)>{{ $h->name }}</option>@endforeach</select>
                    <select name="status" class="form-select form-select-sm"><option value="">Holding a bed</option>@foreach (\App\Models\BedAllocation::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
                @else
                    <select name="app_status" class="form-select form-select-sm">@foreach (\App\Models\AccommodationApplication::STATUSES + ['all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected(request('app_status', 'pending') === $k)>{{ $l }}</option>@endforeach</select>
                @endif
                <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name or matric no.">
                <button class="btn btn-sm btn-light">Filter</button>
            </form>
        </div>
        @if ($tab === 'allocations')
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Student</th><th>Place</th><th>Status</th><th>Fee</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead><tbody>
                @forelse ($allocations as $a)
                    <tr>
                        <td>{{ $a->student->full_name }}<div class="fs-12 text-muted">{{ $a->student->matric_number }} · {{ $a->student->current_level }} level</div></td>
                        <td>{{ $a->room->hostel->name }}<div class="fs-12 text-muted">{{ $a->room->label() }}, bed {{ $a->bed }}</div></td>
                        <td><span class="badge {{ \App\Models\BedAllocation::BADGES[$a->status] }}">{{ \App\Models\BedAllocation::STATUSES[$a->status] }}</span>
                            @if ($a->status === 'reserved')<div class="fs-12 text-muted">Pay by {{ $a->reserved_until->format('j M Y') }}</div>@endif
                            @if ($a->end_reason)<div class="fs-12 text-muted">{{ $a->end_reason }}</div>@endif</td>
                        <td class="fs-13">@if ($a->invoice){{ $a->invoice->reference }} <span class="badge {{ \App\Models\Invoice::BADGES[$a->invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$a->invoice->status] }}</span>@else Free @endif</td>
                        <td><x-last-change :record="$a" :among="$allocations" /></td>
                        <td class="text-end text-nowrap">
                            @if ($a->status === 'confirmed' && $u->can('Check students in and out of hostels'))<form method="POST" action="{{ route('accommodation.check-in', $a) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Check in</button></form>@endif
                            @if ($a->status === 'checked_in' && $u->can('Check students in and out of hostels'))<form method="POST" action="{{ route('accommodation.check-out', $a) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light">Check out</button></form>@endif
                            @if ($a->holdsBed() && $u->can('Allocate accommodation'))
                                <details class="d-inline-block text-start"><summary class="btn btn-sm btn-light">Move</summary>
                                    <form method="POST" action="{{ route('accommodation.move', $a) }}" class="d-flex flex-wrap gap-1 mt-1" style="max-width:340px">@csrf
                                        <select name="hostel_id" data-lookup="hostels" class="form-select form-select-sm">@foreach ($hostels as $h)<option value="{{ $h->id }}" @selected($h->id === $a->room->hostel_id)>{{ $h->name }}</option>@endforeach</select>
                                        <input name="room" class="form-control form-control-sm" style="width:90px" placeholder="Room" required><input name="bed" type="number" min="1" class="form-control form-control-sm" style="width:64px" placeholder="Bed">
                                        <input name="reason" class="form-control form-control-sm" placeholder="Reason" required><button class="btn btn-sm btn-primary">Move</button>
                                    </form></details>
                                <form method="POST" action="{{ route('accommodation.cancel', $a) }}" class="d-inline" onsubmit="var r = prompt('Why is this place being cancelled?'); if (!r) return false; this.reason.value = r;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger">Cancel</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">Nothing here.</td></tr>
                @endforelse
            </tbody></table></div>
            @if ($allocations->hasPages())<div class="card-footer">{{ $allocations->links() }}</div>@endif
        @else
            @php $names = $hostels->pluck('name', 'id'); @endphp
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Student</th><th>Choices</th><th>Applied</th><th>Status</th><th style="width:1%" class="text-nowrap">Last changed by</th></tr></thead><tbody>
                @forelse ($applications as $p)
                    <tr><td>{{ $p->student->full_name }}<div class="fs-12 text-muted">{{ $p->student->matric_number }} · {{ $p->student->current_level }} level</div></td>
                        <td class="fs-13">{{ collect($p->preferences)->map(fn ($id) => $names[$id] ?? '?')->implode(', ') }}</td>
                        <td class="fs-13">{{ $p->created_at->format('j M Y, g:ia') }}</td>
                        <td><span class="badge {{ \App\Models\AccommodationApplication::BADGES[$p->status] }}">{{ \App\Models\AccommodationApplication::STATUSES[$p->status] }}</span></td>
                        <td><x-last-change :record="$p" :among="$applications" /></td></tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5">No applications here.</td></tr>
                @endforelse
            </tbody></table></div>
            @if ($applications->hasPages())<div class="card-footer">{{ $applications->links() }}</div>@endif
        @endif
    </div>
</div></div></div>
@endsection
