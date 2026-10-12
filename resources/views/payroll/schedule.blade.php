@extends('layouts.master')

@section('content')
@php $totals = $schedule->totals(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none flex-wrap gap-2">
        <h4 class="mb-0">{{ $schedule->title }}</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('payroll.runs.show', $run) }}" class="btn btn-light"><i class="ri-arrow-left-line me-1"></i>{{ $run->label() }}</a>
            <a href="{{ request()->fullUrlWithQuery(['download' => 1]) }}" class="btn btn-soft-primary"><i class="ri-download-2-line me-1"></i>Download CSV</a>
            <button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
        </div>
    </div>
    @if ($run->isDraft())<div class="alert alert-warning d-print-none">Draft payroll: do not send this schedule until the payroll is approved.</div>@endif
    <div class="card"><div class="card-body">
        @include('partials.letterhead', ['subtitle' => $schedule->title])
        <div class="table-responsive"><table class="table table-sm table-bordered mb-0">
            <thead class="table-light"><tr>@foreach ($schedule->head as $i => $h)<th class="{{ in_array($i, $schedule->money, true) ? 'text-end' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($schedule->rows as $r)
                <tr>@foreach ($r as $i => $v)<td class="{{ in_array($i, $schedule->money, true) ? 'text-end' : '' }}">{{ in_array($i, $schedule->money, true) ? number_format((float) $v, 2) : $v }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($schedule->head) }}" class="text-center text-muted py-4">Nothing to list.</td></tr>
            @endforelse
            </tbody>
            @if ($totals && $schedule->rows)
                <tfoot><tr class="fw-semibold">@foreach ($schedule->head as $i => $h)<td class="{{ isset($totals[$i]) ? 'text-end' : '' }}">{{ $i === 0 ? 'Total (' . count($schedule->rows) . ')' : (isset($totals[$i]) ? number_format($totals[$i], 2) : '') }}</td>@endforeach</tr></tfoot>
            @endif
        </table></div>
        @include('partials.signatory', ['role' => 'bursar'])
    </div></div>
</div></div></div>
@endsection
