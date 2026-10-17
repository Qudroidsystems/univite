@extends('layouts.master')

@section('content')
@php $u = auth()->user(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Identity Cards', 'icon' => 'ri-id-card-line', 'subtitle' => 'Issue, print and hand over student and staff cards. Each card has a QR code that shows whether it is genuine and still valid.'])
    @include('academics.partials.alerts')
    @if (session('skipped'))
        <div class="alert alert-warning"><div class="fw-semibold mb-1">Not issued:</div><ul class="mb-0 fs-13">@foreach (session('skipped') as $s)<li>{{ $s }}</li>@endforeach</ul>
            <div class="fs-12 mt-1">Upload missing photographs on the student's record (or below for staff) and issue again.</div></div>
    @endif

    @can('Issue identity cards')
    <div class="row">
        <div class="col-lg-5"><div class="card"><div class="card-header"><h6 class="card-title mb-0">Issue to one person</h6></div><div class="card-body">
            <form method="POST" action="{{ route('id-cards.issue') }}" class="row g-2">@csrf
                <div class="col-12"><input name="person" value="{{ old('person') }}" class="form-control" placeholder="Matric number, staff number or email" required></div>
                <div class="col-8"><select name="reason" class="form-select">@foreach (\App\Models\IdCard::REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-4"><button class="btn btn-primary w-100">Issue</button></div>
                <div class="col-12 form-text">Any valid card the person already has is marked replaced.</div>
            </form>
            <hr>
            <form method="POST" action="{{ route('id-cards.photo', 0) }}" enctype="multipart/form-data" class="row g-2" id="staff-photo">@csrf
                <div class="col-12 fw-semibold fs-13">Staff passport photograph</div>
                <div class="col-6"><select class="form-select form-select-sm" required onchange="document.getElementById('staff-photo').action = '{{ url('id-cards/photo') }}/' + this.value"><option value="">Choose staff…</option>@foreach (\App\Models\StaffProfile::with('user')->whereNull('exit_date')->get()->sortBy(fn ($p) => $p->user?->name) as $p)<option value="{{ $p->user_id }}">{{ $p->user?->name }} ({{ $p->staff_number ?? 'no number' }}){{ $p->user?->avatar ? '' : ' · no photo' }}</option>@endforeach</select></div>
                <div class="col-4"><input type="file" name="photo" accept="image/*" class="form-control form-control-sm" required></div>
                <div class="col-2"><button class="btn btn-sm btn-light w-100">Save</button></div>
            </form>
        </div></div></div>
        <div class="col-lg-7"><div class="card"><div class="card-header"><h6 class="card-title mb-0">Issue to a group</h6></div><div class="card-body">
            <form method="POST" action="{{ route('id-cards.batch') }}">@csrf
                @include('partials.audience', ['audience' => new \App\Data\Campus\Audience('students'), 'who' => ['students', 'staff'], 'genders' => false])
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="form-check"><input type="hidden" name="only_without" value="0"><input type="checkbox" name="only_without" value="1" class="form-check-input" id="ow" checked><label for="ow" class="form-check-label">Only people without a valid card</label></div>
                    <button class="btn btn-primary" onclick="return confirm('Issue cards to everyone in this group who can have one?')">Issue cards</button>
                </div>
            </form>
        </div></div></div>
    </div>
    @endcan

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <ul class="nav nav-pills nav-sm">
                @foreach (['requested' => 'Requests', 'active' => 'Valid', 'replaced' => 'Replaced', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)
                    <li class="nav-item"><a class="nav-link py-1 {{ $status === $k ? 'active' : '' }}" href="{{ route('id-cards.index', ['status' => $k]) }}">{{ $l }}@if ($k !== 'all' && ($counts[$k] ?? 0)) <span class="badge bg-light text-body">{{ $counts[$k] }}</span>@endif</a></li>
                @endforeach
            </ul>
            <form method="GET" class="d-flex gap-2"><input type="hidden" name="status" value="{{ $status }}">
                <select name="kind" class="form-select form-select-sm"><option value="">Students and staff</option><option value="student" @selected(request('kind') === 'student')>Students</option><option value="staff" @selected(request('kind') === 'staff')>Staff</option></select>
                <select name="printed" class="form-select form-select-sm"><option value="">Printed or not</option><option value="no" @selected(request('printed') === 'no')>Not printed</option><option value="uncollected" @selected(request('printed') === 'uncollected')>Printed, not collected</option></select>
                <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name, number or serial">
                <button class="btn btn-sm btn-light">Filter</button>
            </form>
            @can('Issue identity cards')@if ($unprinted)<a href="{{ route('id-cards.print', ['kind' => request('kind')]) }}" class="btn btn-sm btn-primary" target="_blank"><i class="ri-printer-line me-1"></i>Print {{ $unprinted }} unprinted</a>@endif @endcan
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Holder</th><th>Card</th><th>Status</th><th>Issued / expires</th><th>Printed / collected</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead><tbody>
            @forelse ($cards as $c)
                @php $holder = $c->snap('name') ?? ($c->user?->student?->full_name ?: $c->user?->name); @endphp
                <tr>
                    <td><div class="fw-semibold">{{ $holder }}</div><div class="fs-12 text-muted">{{ ucfirst($c->kind) }} · {{ $c->snap('number') ?? $c->user?->student?->matric_number ?? $c->user?->staffProfile?->staff_number }}</div></td>
                    <td>{{ $c->serial ?? '—' }}<div class="fs-12 text-muted">{{ \App\Models\IdCard::REASONS[$c->reason] }}</div>
                        @if ($c->request_note)<div class="fs-12 text-muted">“{{ $c->request_note }}”</div>@endif</td>
                    <td><span class="badge {{ $c->stateBadge() }}">{{ $c->stateLabel() }}</span>
                        @if ($c->invoice)<div class="fs-12">Fee {{ $c->invoice->reference }}: <span class="badge {{ \App\Models\Invoice::BADGES[$c->invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$c->invoice->status] }}</span></div>@endif
                        @if ($c->cancel_reason)<div class="fs-12 text-muted">{{ $c->cancel_reason }}</div>@endif</td>
                    <td class="fs-12">{{ $c->issued_at?->format('j M Y') ?? 'Asked ' . $c->requested_at?->format('j M Y') }}@if ($c->expires_on)<div class="text-muted">to {{ $c->expires_on->format('j M Y') }}</div>@endif</td>
                    <td class="fs-12">{{ $c->printed_at?->format('j M Y') ?? '—' }}@if ($c->collected_at)<div class="text-success">Collected {{ $c->collected_at->format('j M Y') }}</div>@endif</td>
                    <td><x-last-change :record="$c" :among="$cards" /></td>
                    <td class="text-end text-nowrap">
                        @if ($c->status === 'requested' && $u->can('Issue identity cards'))
                            <form method="POST" action="{{ route('id-cards.approve', $c) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">Issue</button></form>
                        @endif
                        @if ($c->status === 'active' && $u->can('Issue identity cards'))
                            <a href="{{ route('id-cards.print', ['ids' => $c->id]) }}" target="_blank" class="btn btn-sm btn-light" title="Print"><i class="ri-printer-line"></i></a>
                            @unless ($c->collected_at)<form method="POST" action="{{ route('id-cards.collected', $c) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light" title="Handed over">Collected</button></form>@endunless
                        @endif
                        @if (in_array($c->status, ['active', 'requested']) && $u->can('Cancel identity cards'))
                            <form method="POST" action="{{ route('id-cards.cancel', $c) }}" class="d-inline" onsubmit="var r = prompt('Why is this card being cancelled?'); if (!r) return false; this.reason.value = r;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger">Cancel</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">No cards here.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($cards->hasPages())<div class="card-footer">{{ $cards->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
