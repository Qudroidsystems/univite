@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Retirements due', 'icon' => 'ri-calendar-event-line', 'subtitle' => "Staff who retire at {$rules['retirement_age']} ({$rules['retirement_age_professorial']} for the professorial cadre)" . ($rules['max_service_years'] ? " or after {$rules['max_service_years']} years of service" : '') . ', whichever comes first.'])
    @include('academics.partials.alerts')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <form method="GET" class="d-flex gap-2 align-items-center"><span>Within</span>
                <select name="months" class="form-select" style="width:auto" onchange="this.form.submit()">@foreach ([6, 12, 24, 36, 60] as $m)<option value="{{ $m }}" @selected($months == $m)>{{ $m }} months</option>@endforeach</select></form>
            @if ($missing)<span class="text-warning fs-13"><i class="ri-error-warning-line"></i> {{ $missing }} record(s) lack a date of birth or first appointment, so their retirement cannot be worked out.</span>@endif
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Staff</th><th>Department / unit</th><th>Retires</th><th>Why</th><th class="text-end">Months left</th></tr></thead><tbody>
            @forelse ($rows as $r)
                <tr><td><a href="{{ route('staff.show', $r['profile']) }}" class="fw-semibold">{{ $r['profile']->displayName() }}</a><div class="text-muted fs-12">{{ $r['profile']->staff_number }} {{ $r['profile']->designation }}</div></td>
                    <td>{{ $r['profile']->workplace() ?? '—' }}</td><td>{{ $r['retirement']->date->format('j M Y') }}</td><td>{{ $r['retirement']->reason }}</td>
                    <td class="text-end">@if ($r['retirement']->isPast())<span class="badge bg-danger">Overdue</span>@else{{ $r['retirement']->monthsLeft() }}@endif</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">Nobody retires in the next {{ $months }} months.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
</div></div></div>
@endsection
