@extends('layouts.master')

@section('content')
@php
    $days = collect(\Carbon\CarbonPeriod::create($month, $month->copy()->endOfMonth()));
    $people = $rows->groupBy('user_id');
    $colors = ['annual' => 'primary', 'sick' => 'danger', 'casual' => 'info', 'maternity' => 'pink', 'study' => 'secondary'];
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Leave calendar', 'icon' => 'ri-calendar-line', 'subtitle' => $all ? 'Everyone on approved leave this month.' : 'Your department this month.'])
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <a href="?{{ http_build_query(array_merge(request()->query(), ['month' => $month->copy()->subMonth()->format('Y-m')])) }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-s-line"></i></a>
        <h6 class="mb-0 mx-2">{{ $month->format('F Y') }}</h6>
        <a href="?{{ http_build_query(array_merge(request()->query(), ['month' => $month->copy()->addMonth()->format('Y-m')])) }}" class="btn btn-sm btn-light"><i class="ri-arrow-right-s-line"></i></a>
        <form method="GET" class="d-flex gap-2 ms-auto align-items-center"><input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
            @if ($all)<select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All departments</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected($department == $id)>{{ $n }}</option>@endforeach</select>@endif
            <div class="form-check mb-0"><input type="checkbox" name="pending" value="1" class="form-check-input" id="pend" @checked(request('pending')) onchange="this.form.submit()"><label class="form-check-label fs-12 text-nowrap" for="pend">Include awaiting approval</label></div>
        </form></div>
        <div class="table-responsive"><table class="table table-bordered table-sm mb-0 fs-12" style="min-width:900px">
            <thead class="table-light"><tr><th style="min-width:180px">Staff</th>@foreach ($days as $d)@php $off = ! \App\Services\LeaveService::isWorkingDay($d); @endphp<th class="text-center {{ $off ? 'bg-light text-muted' : '' }}" title="{{ \App\Models\Holiday::nameOn($d) }}">{{ $d->format('j') }}<div class="fw-normal">{{ substr($d->format('D'), 0, 1) }}</div></th>@endforeach</tr></thead>
            <tbody>
            @forelse ($people as $list)
                @php $person = $list->first()->user; @endphp
                <tr><td><strong>{{ $person->name }}</strong><div class="text-muted">{{ $person->staffProfile?->department?->name ?? $person->staffProfile?->unit }}</div></td>
                    @foreach ($days as $d)
                        @php $hit = $list->first(fn ($r) => $d->between($r->start_date, $r->end_date)); @endphp
                        <td class="p-0 {{ ! \App\Services\LeaveService::isWorkingDay($d) ? 'bg-light' : '' }}">@if ($hit)<a href="{{ $all || $hit->user_id === auth()->id() ? route('leave.show', $hit) : '#' }}" title="{{ $hit->type->name }} {{ $hit->reference }}" class="d-block h-100 bg-{{ $colors[$hit->type->code] ?? 'success' }} {{ $hit->status === 'pending' ? 'opacity-50' : '' }}" style="min-height:28px"></a>@endif</td>
                    @endforeach</tr>
            @empty
                <tr><td colspan="{{ $days->count() + 1 }}" class="text-center text-muted py-4">Nobody is on leave this month.</td></tr>
            @endforelse
            </tbody></table></div>
        <div class="card-body fs-12 text-muted">Shaded columns are weekends and holidays. Faded bars are awaiting approval.</div>
    </div>
</div></div></div>
@endsection
