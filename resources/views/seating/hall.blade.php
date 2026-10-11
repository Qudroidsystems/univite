@extends('layouts.master')

@section('content')
@php
    $palette = ['primary', 'success', 'warning', 'info', 'danger', 'secondary', 'dark'];
    $colour = $courses->values()->flip()->map(fn ($i) => $palette[$i % count($palette)]);
    $bySeat = $seats->keyBy('seat_label');
    $rows = $venue->seat_rows ?: (int) ceil(max(1, $venue->exam_capacity) / ($venue->seats_per_row ?: 10));
    $cols = $venue->seats_per_row ?: 10;
    $usable = collect($map)->keyBy('label');
    $f = $seats->first();
    $canMove = auth()->user()->can('Assign exam seats') && $plan->status !== 'superseded';
    $canMark = auth()->user()->can('Record exam attendance');
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <div class="d-print-none">@include('partials.page-title', ['title' => $venue->name, 'icon' => 'ri-layout-grid-line', 'subtitle' => ($f ? $f->date->format('l j M Y') . ', ' . substr($f->starts_at, 0, 5) : '') . ' · ' . $seats->count() . ' candidate(s)', 'back' => [route('seating.show', $t), 'Seating']])</div>
    @include('academics.partials.alerts')
    <div class="d-flex gap-2 mb-2 d-print-none"><button onclick="window.print()" class="btn btn-sm btn-light"><i class="ri-printer-line me-1"></i>Print map and list</button>
        @foreach ($courses as $code)<span class="badge bg-{{ $colour[$code] }}">{{ $code }}</span>@endforeach</div>

    <div class="card"><div class="card-body" style="overflow-x:auto">
        <div class="d-none d-print-block">@include('partials.letterhead', ['subtitle' => 'Seating plan · ' . $venue->name . ($f ? ' · ' . $f->date->format('j M Y') . ' ' . substr($f->starts_at, 0, 5) : '')])</div>
        <div class="text-center text-muted fs-11 mb-2 border rounded py-1">FRONT{{ $venue->entry_info ? ' · ' . $venue->entry_info : '' }}</div>
        <table class="mx-auto" style="border-collapse:separate;border-spacing:3px">
            @for ($r = 1; $r <= $rows; $r++)
                <tr><th class="text-muted fs-11 pe-1">R{{ sprintf('%02d', $r) }}</th>
                @for ($c = 1; $c <= $cols; $c++)
                    @php $label = \App\Models\Venue::seatLabel($r, $c); $s = $bySeat[$label] ?? null; @endphp
                    <td class="text-center rounded fs-10 {{ $s ? 'bg-' . $colour[$s->course?->code] . ' text-white' : ($usable->has($label) ? 'bg-light' : 'bg-danger-subtle') }}" style="width:58px;height:34px;line-height:1.1" title="{{ $label }}{{ $s ? ' · ' . $s->student?->matric_number . ' · ' . $s->student?->full_name : '' }}">
                        @if ($s)<div class="fw-semibold">{{ $s->course?->code }}</div><div>{{ $s->seat_no }}</div>@else<span class="text-muted">{{ $usable->has($label) ? '' : '×' }}</span>@endif
                    </td>
                @endfor</tr>
            @endfor
        </table>
    </div></div>

    <div class="card"><div class="card-header d-flex justify-content-between align-items-center d-print-none"><h6 class="card-title mb-0">Candidate list</h6>
        <form method="GET" class="d-flex gap-2"><input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Matric number or name"><button class="btn btn-sm btn-light">Find</button></form></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Seat</th><th>Matric number</th><th>Name</th><th>Paper</th><th class="d-print-none">Attendance</th>@if ($canMove)<th class="d-print-none">Move</th>@endif</tr></thead>
            <tbody>
            @foreach ($list as $s)
                <tr><td><strong>{{ $s->seat_label }}</strong> <span class="text-muted">({{ $s->seat_no }})</span>@if ($s->moved_from)<div class="text-muted fs-11">was {{ $s->moved_from }}</div>@endif</td>
                    <td>{{ $s->student?->matric_number }}</td><td>{{ $s->student?->full_name }}</td><td><span class="badge bg-{{ $colour[$s->course?->code] }}">{{ $s->course?->code }}</span></td>
                    <td class="d-print-none">@if ($canMark)<form method="POST" action="{{ route('seating.attendance', $s) }}" class="btn-group btn-group-sm">@csrf
                            <button name="mark" value="present" class="btn {{ $s->attendance === 'present' ? 'btn-success' : 'btn-outline-success' }}">Present</button>
                            <button name="mark" value="absent" class="btn {{ $s->attendance === 'absent' ? 'btn-danger' : 'btn-outline-danger' }}">Absent</button></form>
                        @else{{ ucfirst($s->attendance ?? '') }}@endif</td>
                    @if ($canMove)<td class="d-print-none"><form method="POST" action="{{ route('seating.move', $s) }}" class="d-flex gap-1">@csrf
                        <select name="venue_id" class="form-select form-select-sm" style="width:90px">@foreach ($halls as $h)<option value="{{ $h->id }}" @selected($h->id === $venue->id)>{{ $h->code }}</option>@endforeach</select>
                        <input name="seat_label" class="form-control form-control-sm" placeholder="R03-S07" style="width:85px" required>
                        <input name="reason" class="form-control form-control-sm" placeholder="Reason" style="width:120px" required minlength="5">
                        <label class="fs-11 d-flex align-items-center gap-1" title="Allow next to the same paper"><input type="checkbox" name="accept_adjacent" value="1">adj.</label>
                        <button class="btn btn-sm btn-soft-primary">Move</button></form></td>@endif
                </tr>
            @endforeach
            </tbody></table></div></div>
</div></div></div>
@endsection
