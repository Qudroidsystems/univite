@extends('layouts.master')

@section('content')
@php use App\Models\StudentLoan as L; $n = fn ($v) => number_format((float) $v, 2); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'School loans', 'icon' => 'ri-hand-coin-line', 'subtitle' => 'Fee loans: applications, payments against fees and repayments'])
    @include('academics.partials.alerts')

    <div class="row">
        @foreach ([['Still owed', $totals['out'], 'ri-funds-line', 'primary'], ['Overdue', $totals['overdue'], 'ri-alarm-warning-line', 'danger'], ['Lent so far', $totals['lent'], 'ri-hand-coin-line', 'info'], ['Repaid so far', $totals['repaid'], 'ri-checkbox-circle-line', 'success']] as [$l, $v, $i, $c])
            <div class="col-md-3 col-6"><div class="card"><div class="card-body d-flex justify-content-between align-items-center">
                <div><div class="text-muted fs-12 text-uppercase fw-semibold">{{ $l }}</div><div class="fs-4 fw-semibold">{{ $n($v) }}</div>@if ($l === 'Overdue')<div class="fs-12 text-muted">{{ $totals['overdueCount'] }} loans</div>@endif</div>
                <span class="avatar-sm"><span class="avatar-title bg-{{ $c }}-subtle text-{{ $c }} rounded fs-3"><i class="{{ $i }}"></i></span></span></div></div></div>
        @endforeach
    </div>

    <ul class="nav nav-pills mb-3 gap-1">
        @foreach (['pending' => 'Awaiting decision', 'approved' => 'To pay out', 'disbursed' => 'Repaying', 'overdue' => 'Overdue', 'settled' => 'Repaid', 'declined' => 'Declined', 'all' => 'All'] as $k => $l)
            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ route('loans.index', ['status' => $k]) }}">{{ $l }}@if (isset($counts[$k]))<span class="badge bg-light text-body ms-1">{{ $counts[$k] }}</span>@endif</a></li>
        @endforeach
    </ul>

    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Loan</th><th>Student</th><th class="text-end">Asked</th><th class="text-end">Approved</th><th class="text-end">Owed</th><th>Next instalment</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($rows as $l)
            @php $next = $l->nextInstalment(); $od = $l->overdue(); @endphp
            <tr>
                <td class="fw-semibold">{{ $l->reference }}<div class="fs-12 text-muted">{{ $l->session?->name }} · {{ $l->created_at->format('j M Y') }}</div></td>
                <td>{{ $l->student?->full_name }}<div class="fs-12 text-muted">{{ $l->student?->matric_number }} · {{ $l->student?->programme?->code }}</div></td>
                <td class="text-end">{{ $n($l->amount_requested) }}</td>
                <td class="text-end">{{ $l->amount_approved !== null ? $n($l->amount_approved) : '—' }}</td>
                <td class="text-end">{{ $l->status === 'disbursed' ? $n($l->balance) : '—' }}@if ($od > 0)<div class="fs-11 text-danger">{{ $n($od) }} overdue</div>@endif</td>
                <td class="fs-13">{{ $l->status === 'disbursed' && $next ? \Illuminate\Support\Carbon::parse($next['due'])->format('j M Y') . ' · ' . $n($next['amount']) : '—' }}</td>
                <td><span class="badge {{ L::BADGES[$l->status] }}">{{ L::STATUSES[$l->status] }}</span></td>
                <td class="text-end"><a href="{{ route('loans.show', $l->id) }}" class="btn btn-sm btn-soft-primary">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No loans here.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>

    @can('Set loan policy')
        <form method="POST" action="{{ route('loans.settings') }}" class="card">@csrf @method('PUT')
            <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Loan scheme rules</h6>
                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="len" @checked($settings['enabled'] === '1')><label class="form-check-label" for="len">Students may apply</label></div></div>
            <div class="card-body"><div class="row g-3">
                <div class="col-md-2"><label class="form-label mb-1">Most a student may borrow</label><div class="input-group input-group-sm"><input type="number" step="1" min="1" max="100" name="max_percent" value="{{ $settings['max_percent'] }}" class="form-control"><span class="input-group-text">% of fees</span></div></div>
                <div class="col-md-2"><label class="form-label mb-1">Interest (flat)</label><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" max="100" name="interest_percent" value="{{ $settings['interest_percent'] }}" class="form-control"><span class="input-group-text">%</span></div></div>
                <div class="col-md-2"><label class="form-label mb-1">Most instalments</label><input type="number" min="1" max="24" name="max_instalments" value="{{ $settings['max_instalments'] }}" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label mb-1">First repayment after</label><div class="input-group input-group-sm"><input type="number" min="0" max="365" name="first_due_days" value="{{ $settings['first_due_days'] }}" class="form-control"><span class="input-group-text">days</span></div></div>
                <div class="col-md-2"><label class="form-label mb-1">Then every</label><div class="input-group input-group-sm"><input type="number" min="7" max="365" name="interval_days" value="{{ $settings['interval_days'] }}" class="form-control"><span class="input-group-text">days</span></div></div>
                <div class="col-md-2 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="require_guarantor" value="1" id="lg" @checked($settings['require_guarantor'] === '1')><label class="form-check-label" for="lg">Guarantor needed</label></div></div>
                <div class="col-12"><label class="form-label mb-1">Terms shown to students</label><textarea name="terms" rows="2" class="form-control form-control-sm">{{ $settings['terms'] }}</textarea></div>
            </div><div class="form-text">Whether a loan counts as fees paid, and whether an overdue repayment locks registration, examinations, results or the portal, is set under <a href="{{ route('fee-access.index') }}">Fee Access &amp; Eligibility</a>.</div></div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm">Save</button></div>
        </form>
    @endcan
</div></div></div>
@endsection
