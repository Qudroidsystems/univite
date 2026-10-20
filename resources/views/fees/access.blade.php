@extends('layouts.master')

@section('content')
@php
    use App\Services\Fees\FeeAccess;
    $n = fn ($v) => number_format((float) $v, 2);
    $p = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div><h4 class="mb-0"><i class="ri-shield-keyhole-line me-1"></i>Fee access &amp; eligibility</h4>
            <div class="text-muted fs-13">{{ $session?->name ?? 'No current session' }} · who may register, write examinations, see results and use the portal</div></div>
        <div class="d-flex gap-2">
            <a href="{{ route('fee-access.print', request()->query()) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-printer-line me-1"></i>Print / PDF</a>
            <a href="{{ route('fee-access.excel', request()->query()) }}" class="btn btn-sm btn-light"><i class="ri-file-excel-2-line me-1"></i>Excel</a>
        </div>
    </div>
    @include('academics.partials.alerts')

    <div class="row">
        @foreach ($summary as $area => $s)
            <div class="col-md-3 col-6"><a class="card card-animate text-decoration-none" href="{{ request()->fullUrlWithQuery(['area' => $area, 'state' => 'blocked', 'page' => null]) }}"><div class="card-body">
                <div class="d-flex justify-content-between"><span class="text-muted fs-12 text-uppercase fw-semibold">{{ $s['label'] }}</span>
                    @if ($s['on'])<span class="badge bg-primary-subtle text-primary">{{ $s['percent'] }}% needed</span>@else<span class="badge bg-light text-muted">Not checked</span>@endif</div>
                <div class="fs-3 fw-semibold mt-2 {{ $s['blocked'] ? 'text-danger' : 'text-body' }}">{{ $s['blocked'] }}</div><div class="text-muted fs-12">of {{ $total }} students locked</div>
            </div></a></div>
        @endforeach
    </div>

    @if ($canSet)
        <form method="POST" action="{{ route('fee-access.update') }}" class="card">
            @csrf @method('PUT')
            <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Rules</h6><span class="text-muted fs-12">Applies to every student with fees billed for the session. Students with nothing billed are never held back.</span></div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach (FeeAccess::AREAS as $area => [$label])
                        <div class="col-md-3">
                            <div class="border rounded p-2 h-100">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="{{ $area }}_on" value="1" id="on-{{ $area }}" @checked($settings["{$area}_on"] === '1')><label class="form-check-label fw-medium" for="on-{{ $area }}">{{ $label }}</label></div>
                                <div class="input-group input-group-sm mt-2"><span class="input-group-text">Needs</span><input type="number" min="0" max="100" name="{{ $area }}_percent" value="{{ $settings["{$area}_percent"] }}" class="form-control"><span class="input-group-text">% paid</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="d-flex flex-wrap gap-4 mt-3">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="count_loans" value="1" id="cl" @checked($settings['count_loans'] === '1')><label class="form-check-label" for="cl">School loans paid against fees count as paid</label></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="loan_overdue_blocks" value="1" id="lo" @checked($settings['loan_overdue_blocks'] === '1')><label class="form-check-label" for="lo">An overdue loan repayment locks what is switched on</label></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="include_arrears" value="1" id="ar" @checked($settings['include_arrears'] === '1')><label class="form-check-label" for="ar">Money owed from earlier sessions locks what is switched on</label></div>
                </div>
                <div class="form-text">Course registration can also check fees per semester under Registration Settings; both apply.</div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm"><i class="ri-save-line me-1"></i>Save rules</button></div>
        </form>
    @endif

    <form method="GET" class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div><label class="form-label mb-1">Session</label><select name="session_id" class="form-select form-select-sm">@foreach ($sessions as $v => $l)<option value="{{ $v }}" @selected($session?->id === $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="form-label mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($departments as $v => $l)<option value="{{ $v }}" @selected((string) request('department_id') === (string) $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="form-label mb-1">Programme</label><select name="programme_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($programmes as $v => $l)<option value="{{ $v }}" @selected((string) request('programme_id') === (string) $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="form-label mb-1">Level</label><select name="level" class="form-select form-select-sm"><option value="">All</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected((string) request('level') === (string) $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="form-label mb-1">Show</label><select name="area" class="form-select form-select-sm"><option value="">Everyone</option>@foreach (FeeAccess::AREAS as $v => [$l])<option value="{{ $v }}" @selected(request('area') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="form-label mb-1">&nbsp;</label><select name="state" class="form-select form-select-sm"><option value="">any</option><option value="allowed" @selected(request('state') === 'allowed')>open</option><option value="blocked" @selected(request('state') === 'blocked')>locked</option></select></div>
        <div class="flex-grow-1"><label class="form-label mb-1">Student</label><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name or matric number"></div>
        <button class="btn btn-primary btn-sm">Show</button>
    </div></form>

    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Student</th><th class="text-end">Billed</th><th class="text-end">Paid</th><th style="width:12%">% paid</th>@foreach (FeeAccess::AREAS as [$label])<th class="text-center fs-12">{{ $label }}</th>@endforeach @if ($canOverride)<th></th>@endif</tr></thead>
        <tbody>
        @forelse ($rows as $r)
            @php $s = $r['standing']; $st = $r['student']; @endphp
            <tr>
                <td><span class="fw-semibold">{{ $st->full_name }}</span><div class="fs-12 text-muted">{{ $st->matric_number }} · {{ $st->programme?->code }} · {{ $st->current_level }}L</div></td>
                <td class="text-end">{{ $s->billed ? $n($s->total) : '—' }}</td>
                <td class="text-end">{{ $s->billed ? $n($s->paid) : '—' }}@if ($s->loan > 0)<div class="fs-11 text-muted">loan {{ $n($s->loan) }}</div>@endif</td>
                <td>@if ($s->billed)<div class="progress" style="height:6px"><div class="progress-bar {{ $s->percent >= 100 ? 'bg-success' : 'bg-info' }}" style="width:{{ min(100, $s->percent) }}%"></div></div><span class="fs-12">{{ $p($s->percent) }}%</span>@if ($s->loanOverdue > 0)<div class="fs-11 text-danger">loan overdue</div>@endif @else<span class="text-muted fs-12">nothing billed</span>@endif</td>
                @foreach (FeeAccess::AREAS as $area => $x)
                    @php $a = $s->areas[$area]; @endphp
                    <td class="text-center" title="{{ $a['reason'] ?? $a['override'] }}">@if ($a['allowed'])<i class="ri-checkbox-circle-fill text-success"></i>@else<i class="ri-lock-fill text-danger"></i>@endif @if ($a['override'])<i class="ri-user-settings-line text-warning" title="{{ $a['override'] }}"></i>@endif</td>
                @endforeach
                @if ($canOverride)
                    <td class="text-end"><button type="button" class="btn btn-sm btn-soft-secondary" data-bs-toggle="collapse" data-bs-target="#ov{{ $st->id }}">Exception</button></td>
                @endif
            </tr>
            @if ($canOverride)
                <tr class="collapse" id="ov{{ $st->id }}"><td colspan="9" class="bg-light">
                    <form method="POST" action="{{ route('fee-access.override', $st->id) }}" class="d-flex flex-wrap gap-2 align-items-center">
                        @csrf <input type="hidden" name="session_id" value="{{ $session?->id }}">
                        <select name="area" class="form-select form-select-sm w-auto">@foreach (FeeAccess::AREAS as $v => [$l])<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        <select name="decision" class="form-select form-select-sm w-auto"><option value="allow">Allow despite fees</option><option value="bar">Bar</option><option value="clear">Back to the rule</option></select>
                        <input name="reason" class="form-control form-control-sm" style="max-width:320px" placeholder="Reason (e.g. scholarship letter seen)">
                        <input type="date" name="expires_on" class="form-control form-control-sm w-auto" title="Until (optional)">
                        <button class="btn btn-sm btn-primary">Save</button>
                    </form></td></tr>
            @endif
        @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No students match.</td></tr>
        @endforelse
        </tbody>
    </table></div><div class="card-footer">{{ $rows->links() }}</div></div>
</div></div></div>
@endsection
