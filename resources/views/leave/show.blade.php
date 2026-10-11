@extends('layouts.master')

@section('content')
@php
    $u = auth()->user();
    $stepBadge = ['approved' => 'bg-success', 'rejected' => 'bg-danger', 'returned' => 'bg-info', 'pending' => 'bg-warning', 'skipped' => 'bg-light text-muted', 'waiting' => 'bg-light text-muted'];
    $canResume = $r->status === 'approved' && ($u->can('Record staff resumption') || ($own && ! $r->resumed_on && today()->gte($r->start_date)));
    $canCancel = in_array($r->status, ['pending', 'returned', 'approved'], true) && ($r->status !== 'approved' || today()->lt($r->start_date)) && ($own || $u->can('Approve leave for any staff'));
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $r->type->name . ' · ' . $r->user->name, 'icon' => 'ri-suitcase-line', 'subtitle' => $r->reference . ' · submitted ' . $r->submitted_at?->format('j M Y H:i'), 'back' => $own ? [route('leave.index'), 'My leave'] : [route('leave.approvals'), 'Approvals']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row">
        <div class="col-xl-8">
            <div class="card"><div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0">Application</h6><span class="badge fs-12 {{ \App\Models\LeaveRequest::BADGES[$r->status] }}">{{ $r->phase }}</span></div>
                <div class="card-body"><div class="row g-3 fs-13">
                    <div class="col-md-4"><div class="text-muted">Dates</div><strong>{{ $r->start_date->format('D j M Y') }} – {{ $r->end_date->format('D j M Y') }}</strong></div>
                    <div class="col-md-4"><div class="text-muted">Days</div><strong>{{ $r->days }} {{ $r->type->working_days ? 'working' : 'calendar' }} day(s)</strong>@if ($r->days_used !== null)<div class="text-muted">{{ $r->days_used }} used (came back early)</div>@endif</div>
                    <div class="col-md-4"><div class="text-muted">Expected back</div><strong>{{ $r->expected_resumption->format('D j M Y') }}</strong>@if ($r->resumed_on)<div class="{{ $r->late_days ? 'text-danger' : 'text-success' }}">Resumed {{ $r->resumed_on->format('j M Y') }}{{ $r->late_days ? ', ' . $r->late_days . ' working day(s) late' : '' }}</div>@endif</div>
                    <div class="col-md-4"><div class="text-muted">Staff</div>{{ $r->user->name }}@if ($p = $r->user->staffProfile)<div class="text-muted">{{ collect([$p->staff_number, $p->designation, $p->department?->name ?? $p->unit])->filter()->implode(' · ') }}</div>@endif</div>
                    <div class="col-md-4"><div class="text-muted">Acting officer</div>{{ $r->actingOfficer?->name ?? 'None' }}@if ($r->actingOfficer)<span class="badge {{ ['accepted' => 'bg-success-subtle text-success', 'declined' => 'bg-danger-subtle text-danger'][$r->acting_status] ?? 'bg-warning-subtle text-warning' }}">{{ ucfirst($r->acting_status ?? 'pending') }}</span>@endif</div>
                    <div class="col-md-4"><div class="text-muted">Contact while away</div>{{ $r->phone_during_leave ?: '—' }}@if ($r->address_during_leave)<div class="text-muted">{{ $r->address_during_leave }}</div>@endif</div>
                    @if ($r->reason)<div class="col-12"><div class="text-muted">Reason</div>{{ $r->reason }}</div>@endif
                    @if ($r->decision_note)<div class="col-12"><div class="text-muted">{{ $r->status === 'approved' ? 'Note' : 'Reason given' }}</div>{{ $r->decision_note }}</div>@endif
                </div></div>
                @if ($r->warnings)
                    <div class="card-body border-top">@foreach ($r->warnings as $w)<div class="text-warning fs-13"><i class="ri-alert-line me-1"></i>{{ $w }}</div>@endforeach</div>
                @endif
            </div>

            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Approval trail</h6></div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item fs-13"><span class="badge bg-primary me-2">Submitted</span>{{ $r->user->name }} · {{ $r->submitted_at?->format('j M Y H:i') }}</li>
                    @foreach ($r->steps as $s)
                        <li class="list-group-item fs-13"><span class="badge {{ $stepBadge[$s->status] ?? 'bg-light' }} me-2">{{ ucfirst($s->status) }}</span><strong>{{ $s->label }}</strong>
                            @if ($s->actor) · {{ $s->actor->name }}, {{ $s->acted_at?->format('j M Y H:i') }}@elseif ($s->status === 'pending') · waiting for {{ $approvers->pluck('name')->implode(', ') ?: 'a leave administrator' }}@if ($s->due_at) <span class="{{ $s->due_at->isPast() ? 'text-danger' : 'text-muted' }}">(due {{ $s->due_at->format('j M') }}{{ $s->escalated_at ? ', escalated' : '' }})</span>@endif @endif
                            @if ($s->comment)<div class="text-muted ms-1">{{ $s->comment }}</div>@endif</li>
                    @endforeach
                </ul></div>

            @if ($canDecide)
                <div class="card border-warning"><div class="card-header"><h6 class="card-title mb-0">Your decision</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('leave.decide', $r) }}">@csrf
                        <textarea name="comment" rows="2" class="form-control mb-2" placeholder="Comment (required to reject or return)"></textarea>
                        <div class="d-flex flex-wrap gap-2">
                            <button name="action" value="approve" class="btn btn-success"><i class="ri-check-line me-1"></i>Approve</button>
                            <button name="action" value="return" class="btn btn-info">Return for correction</button>
                            <button name="action" value="reject" class="btn btn-danger" onclick="return confirm('Refuse this application?')">Do not approve</button>
                        </div>
                    </form>
                </div></div>
            @endif

            @if ($r->acting_officer_id === $u->id && $r->acting_status === 'pending')
                <div class="card border-info"><div class="card-body d-flex flex-wrap gap-2 align-items-center"><span class="me-auto">{{ $r->user->name }} has named you acting officer.</span>
                    <form method="POST" action="{{ route('leave.acting', $r) }}">@csrf<input type="hidden" name="accept" value="1"><button class="btn btn-sm btn-success">Accept</button></form>
                    <form method="POST" action="{{ route('leave.acting', $r) }}">@csrf<input type="hidden" name="accept" value="0"><button class="btn btn-sm btn-light">Decline</button></form></div></div>
            @endif
        </div>

        <div class="col-xl-4">
            @if ($own && $r->status === 'returned')<a href="{{ route('leave.edit', $r) }}" class="btn btn-primary w-100 mb-3"><i class="ri-edit-line me-1"></i>Correct and resubmit</a>@endif

            @if ($balance)
                <div class="card"><div class="card-body fs-13"><div class="text-muted mb-1">{{ $r->type->name }} balance for {{ $r->year }}</div>
                    <div class="d-flex justify-content-between"><span>Entitled{{ $balance->carried ? ' + carried' : '' }}</span><strong>{{ $balance->allowed() }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Taken</span><strong>{{ $balance->taken }}</strong></div>
                    <div class="d-flex justify-content-between"><span>Awaiting approval</span><strong>{{ $balance->pending }}</strong></div>
                    <div class="d-flex justify-content-between border-top mt-1 pt-1"><span>Available</span><strong class="{{ $balance->available() < 0 ? 'text-danger' : 'text-success' }}">{{ $balance->available() + 0 }}</strong></div></div></div>
            @endif

            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Documents</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($r->documents as $d)
                        <li class="list-group-item fs-13">@if ($own || ! $d->confidential || $u->can('View confidential leave documents'))<a href="{{ route('leave.document', $d) }}" target="_blank"><i class="ri-file-line me-1"></i>{{ $d->original_name }}</a>@else<span class="text-muted"><i class="ri-lock-line me-1"></i>{{ $r->type->document_label ?: 'Document' }} attached (confidential)</span>@endif</li>
                    @empty
                        <li class="list-group-item text-muted fs-13">None attached.</li>
                    @endforelse
                </ul></div>

            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Letters</h6></div>
                <ul class="list-group list-group-flush">@foreach ($letters as $k)<li class="list-group-item fs-13"><a href="{{ route('leave.letter', [$r, $k]) }}" target="_blank"><i class="ri-printer-line me-1"></i>{{ \App\Support\LeaveLetters::KINDS[$k] }}</a></li>@endforeach</ul></div>

            @if ($canResume)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Resumption</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('leave.resume', $r) }}">@csrf
                        <label class="form-label fs-12">Resumed on</label><input type="date" name="resumed_on" value="{{ ($r->resumed_on ?? today())->toDateString() }}" class="form-control form-control-sm mb-2" required>
                        <input name="note" class="form-control form-control-sm mb-2" placeholder="Note (optional)" value="{{ $r->resumption_note }}">
                        <button class="btn btn-sm btn-success w-100">{{ $r->resumed_on ? 'Correct resumption' : 'Record resumption' }}</button>
                    </form></div></div>
            @endif

            @if ($canCancel)
                <div class="card"><div class="card-body"><form method="POST" action="{{ route('leave.cancel', $r) }}" onsubmit="return confirm('Cancel this leave?')">@csrf
                    <input name="reason" class="form-control form-control-sm mb-2" placeholder="Why it is being cancelled" required>
                    <button class="btn btn-sm btn-outline-danger w-100">Cancel this leave</button></form></div></div>
            @endif

            @if ($history->isNotEmpty() && ! $own)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Recent leave by {{ $r->user->name }}</h6></div>
                    <ul class="list-group list-group-flush">@foreach ($history as $h)<li class="list-group-item fs-13">{{ $h->type->name }} · {{ $h->start_date->format('j M Y') }}, {{ $h->days }} day(s)</li>@endforeach</ul></div>
            @endif
        </div>
    </div>
</div></div></div>
@endsection
