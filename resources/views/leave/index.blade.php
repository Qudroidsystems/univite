@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My leave', 'icon' => 'ri-suitcase-line', 'subtitle' => ($profile?->designation ? $profile->designation . ' · ' : '') . ($profile?->department?->name ?? $profile?->unit ?? '')])
    @include('academics.partials.alerts')

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('leave.create') }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Apply for leave</a>
        <a href="{{ route('leave.calendar') }}" class="btn btn-light"><i class="ri-calendar-line me-1"></i>Leave calendar</a>
        @if ($waiting)<a href="{{ route('leave.approvals') }}" class="btn btn-warning"><i class="ri-inbox-line me-1"></i>{{ $waiting }} waiting for your decision</a>@endif
    </div>

    @if ($current)
        @php
            $today = today();
            $starts = $current->start_date; $back = $current->expected_resumption;
            $before = $today->lt($starts); $on = ! $before && $today->lt($back);
            $left = $on ? \App\Services\LeaveService::workingDaysBetween($today, $back->copy()->subDay()) : 0;
            $total = max(1, $starts->diffInDays($back)); $pct = $on ? min(100, round($starts->diffInDays($today) / $total * 100)) : ($before ? 0 : 100);
        @endphp
        <div class="card border-{{ $before ? 'info' : ($on ? 'success' : 'danger') }}"><div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-md-3 text-center">
                    @if ($before)
                        <div class="display-5 fw-semibold text-info">{{ (int) $today->diffInDays($starts) }}</div><div class="text-muted">day(s) until your leave starts</div>
                    @elseif ($on)
                        <div class="display-5 fw-semibold text-success">{{ $left }}</div><div class="text-muted">working day(s) of leave left</div>
                    @else
                        <div class="display-6 fw-semibold text-danger">Due back</div><div class="text-muted">since {{ $back->format('j M') }}</div>
                    @endif
                </div>
                <div class="col-md-9">
                    <h5 class="mb-1"><a href="{{ route('leave.show', $current) }}">{{ $current->type->name }}</a> <span class="text-muted fs-13">{{ $current->reference }}</span></h5>
                    <div class="mb-2">{{ $starts->format('l j M Y') }} to {{ $current->end_date->format('l j M Y') }} · {{ $current->days }} day(s). You are expected back on <strong>{{ $back->format('l j M Y') }}</strong>.</div>
                    <div class="progress" style="height:8px"><div class="progress-bar bg-{{ $before ? 'info' : ($on ? 'success' : 'danger') }}" style="width:{{ $pct }}%"></div></div>
                    @if (! $before)
                        <form method="POST" action="{{ route('leave.resume', $current) }}" class="d-flex flex-wrap gap-2 align-items-end mt-3">@csrf
                            <div><label class="form-label fs-12 mb-1">I resumed on</label><input type="date" name="resumed_on" value="{{ min($today->toDateString(), max($today->toDateString(), $starts->toDateString())) }}" max="{{ $today->toDateString() }}" class="form-control form-control-sm" required></div>
                            <button class="btn btn-sm btn-success">Confirm my resumption</button>
                            <span class="text-muted fs-12">Coming back early gives the unused days back to your balance.</span>
                        </form>
                    @endif
                </div>
            </div>
        </div></div>
    @endif

    @if ($acting->isNotEmpty())
        <div class="card"><div class="card-header"><h6 class="card-title mb-0"><i class="ri-user-shared-line me-1"></i>You are named as acting officer</h6></div>
            <ul class="list-group list-group-flush">
                @foreach ($acting as $a)
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div><a href="{{ route('leave.show', $a) }}"><strong>{{ $a->user->name }}</strong></a> · {{ $a->type->name }}, {{ $a->start_date->format('j M') }} to {{ $a->end_date->format('j M Y') }}
                            <span class="badge {{ ['accepted' => 'bg-success-subtle text-success', 'declined' => 'bg-danger-subtle text-danger'][$a->acting_status] ?? 'bg-warning-subtle text-warning' }}">{{ ucfirst($a->acting_status ?? 'pending') }}</span></div>
                        @if ($a->acting_status === 'pending')
                            <div class="d-flex gap-1">
                                <form method="POST" action="{{ route('leave.acting', $a) }}">@csrf<input type="hidden" name="accept" value="1"><button class="btn btn-sm btn-success">Accept</button></form>
                                <form method="POST" action="{{ route('leave.acting', $a) }}">@csrf<input type="hidden" name="accept" value="0"><button class="btn btn-sm btn-light">Decline</button></form>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul></div>
    @endif

    <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Balances for {{ $year }}</h6>
        <form method="GET"><select name="year" class="form-select form-select-sm" onchange="this.form.submit()">@foreach (range(now()->year + 1, now()->year - 3) as $y)<option @selected($y === $year)>{{ $y }}</option>@endforeach</select></form></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Leave type</th><th class="text-end">Entitled</th><th class="text-end">Carried over</th><th class="text-end">Adjusted</th><th class="text-end">Taken</th><th class="text-end">Awaiting approval</th><th class="text-end">Available</th></tr></thead>
            <tbody>
            @foreach ($balances as $b)
                <tr><td><strong>{{ $b['type']->name }}</strong>@unless ($b['type']->deducts_balance)<div class="text-muted fs-12">Granted per case, not from a yearly balance</div>@endunless</td>
                    <td class="text-end">{{ $b['balance']->entitled }}</td><td class="text-end">{{ $b['balance']->carried + 0 ?: '—' }}</td><td class="text-end">{{ $b['balance']->adjusted + 0 ?: '—' }}</td>
                    <td class="text-end">{{ $b['balance']->taken ?: '—' }}</td><td class="text-end">{{ $b['balance']->pending ?: '—' }}</td>
                    <td class="text-end fw-semibold {{ $b['balance']->available() <= 0 ? 'text-danger' : 'text-success' }}">{{ $b['type']->deducts_balance ? $b['balance']->available() + 0 : '—' }}</td></tr>
            @endforeach
            </tbody></table></div></div>

    <div class="card"><div class="card-header"><h6 class="card-title mb-0">My applications</h6></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Reference</th><th>Leave</th><th>Dates</th><th>Days</th><th>Where it stands</th><th style="width:1%" class="text-nowrap">Last changed by</th></tr></thead>
            <tbody>
            @forelse ($requests as $r)
                <tr><td><a href="{{ route('leave.show', $r) }}" class="fw-semibold">{{ $r->reference }}</a></td><td>{{ $r->type->name }}</td>
                    <td>{{ $r->start_date->format('j M') }} – {{ $r->end_date->format('j M Y') }}</td><td>{{ $r->days }}</td>
                    <td><span class="badge {{ \App\Models\LeaveRequest::BADGES[$r->status] }}">{{ $r->phase }}</span>
                        @if ($r->status === 'pending' && ($s = $r->currentStep()))<div class="text-muted fs-12">With: {{ $s->label }}</div>@endif
                        @if ($r->status === 'returned')<div><a href="{{ route('leave.edit', $r) }}" class="fs-12">Correct and resubmit</a></div>@endif</td>
                    <td><x-last-change :record="$r" :among="$requests" /></td></tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">You have not applied for leave yet.</td></tr>
            @endforelse
            </tbody></table></div></div>
</div></div></div>
@endsection
