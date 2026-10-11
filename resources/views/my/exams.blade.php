@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1000px">
    @include('partials.page-title', ['title' => 'My examinations', 'icon' => 'ri-file-edit-line', 'subtitle' => $semester ? $semester->session?->name . ' · ' . $semester->name : null])
    @if ($timetables->isEmpty())
        <div class="card"><div class="card-body text-muted">The examination timetable has not been published yet. You will be notified when it is.</div></div>
    @else
        @if ($clash->isNotEmpty())<div class="alert alert-danger">Two of your papers are at the same time. Report this to your department or the examinations office at once.</div>@endif
        <div class="d-flex justify-content-end mb-2">@if ($seats->isNotEmpty())<a href="{{ route('my.exams.slip') }}" target="_blank" class="btn btn-sm btn-primary"><i class="ri-printer-line me-1"></i>Print my seating slip</a>@endif</div>
        <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Date and time</th><th>Paper</th><th>Hall</th><th>Seat number</th></tr></thead>
            <tbody>
            @forelse ($papers as $rows)
                @php $e = $rows->first(); $seat = $seats[$e->course_id . '|' . $e->slots[0]] ?? null; @endphp
                <tr><td><strong>{{ $e->date?->format('D j M Y') }}</strong><div class="text-muted">{{ substr($e->starts_at, 0, 5) }}–{{ substr($e->ends_at, 0, 5) }}</div></td>
                    <td><strong>{{ $e->course?->code }}</strong><div class="fs-13">{{ $e->course?->title }}</div></td>
                    <td>@if ($seat){{ $seat->venue?->name }}@if ($seat->venue?->entry_info)<div class="text-muted fs-12">Entry: {{ $seat->venue->entry_info }}</div>@endif @else{{ $rows->map(fn ($r) => $r->venue?->name)->filter()->implode(' or ') }}@endif</td>
                    <td>@if ($seat)<span class="badge bg-success fs-13">{{ $seat->seat_number }}</span><div class="text-muted fs-12">seat {{ $seat->seat_no }}</div>@else<span class="text-muted fs-13">Shown when the seating plan is published</span>@endif</td></tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">None of your courses are on the published examination timetable.</td></tr>
            @endforelse
            </tbody></table></div></div>
    @endif
</div></div></div>
@endsection
