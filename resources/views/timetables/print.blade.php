<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $t->title }}</title>
@include('partials.brand-head')
<link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
<style>body{font-size:12px;padding:16px} table td,table th{padding:4px!important;vertical-align:top} @media print{.no-print{display:none}} .chip{border:1px solid #ccc;border-radius:3px;padding:2px 4px;margin-bottom:3px}</style></head>
<body>
@php
    $cells = [];
    foreach ($entries->groupBy('unit_key') as $rows) {
        $e = $rows->first();
        $cells[$t->isExam() ? $e->date?->toDateString() : $e->day][substr($e->starts_at, 0, 5)][] = [$e, $rows->map(fn ($r) => $r->venue?->code . ($rows->count() > 1 ? " ({$r->seats})" : ''))->implode(', ')];
    }
@endphp
<div class="no-print mb-2"><button onclick="window.print()" class="btn btn-sm btn-primary">Print</button></div>
@include('partials.letterhead', ['subtitle' => $t->title . ' · ' . $t->session?->name . ' · ' . $t->semesterType?->name . ($t->status !== 'published' ? ' · ' . strtoupper(\App\Models\Timetable::STATUSES[$t->status]) : '')])
<table class="table table-bordered">
    <thead><tr><th></th>@foreach ($grid['cols'] as $l)<th>{{ $l }}</th>@endforeach</tr></thead>
    <tbody>@foreach ($grid['rows'] as $rk => $rl)<tr><th>{{ $rl }}</th>@foreach ($grid['cols'] as $ck => $cl)<td>@foreach ($cells[$rk][$ck] ?? [] as [$e, $v])<div class="chip"><strong>{{ $e->item?->label }}</strong> {{ $v }}@if (! $t->isExam() && count($e->slots) > 1) · {{ count($e->slots) }}h @endif</div>@endforeach</td>@endforeach</tr>@endforeach</tbody>
</table>
<div class="text-muted">Printed {{ now()->format('j M Y H:i') }}</div>
</body></html>
