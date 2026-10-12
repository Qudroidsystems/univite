@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Seat check', 'icon' => 'ri-qr-scan-2-line'])
    @include('academics.partials.alerts')
    <div class="card {{ $current ? 'border-success' : 'border-danger' }}"><div class="card-body">
        @unless ($current)<div class="alert alert-danger">This slip is from a seating plan that is no longer published. The candidate may have a different seat now.</div>@endunless
        <div class="d-flex gap-3 align-items-center mb-3">@if ($seat->student?->photo_url ?? null)<img src="{{ $seat->student->photo_url }}" alt="" style="width:72px;height:72px;object-fit:cover" class="rounded">@endif
            <div><div class="fs-5 fw-semibold">{{ $seat->student?->full_name }}</div><div>{{ $seat->student?->matric_number }} · {{ $seat->student?->programme?->name }}</div></div></div>
        <table class="table table-sm mb-3">
            <tr><th>Paper</th><td>{{ $seat->course?->code }} · {{ $seat->course?->title }}</td></tr>
            <tr><th>When</th><td>{{ $seat->date->format('l j M Y') }}, {{ substr($seat->starts_at, 0, 5) }}–{{ substr($seat->ends_at, 0, 5) }}</td></tr>
            <tr><th>Hall</th><td>{{ $seat->venue?->name }}</td></tr>
            <tr><th>Seat</th><td class="fs-4 fw-bold">{{ $seat->seat_number }}</td></tr>
            <tr><th>Attendance</th><td>{{ $seat->attendance ? ucfirst($seat->attendance) . ' · ' . $seat->checked_at?->format('H:i') : 'Not checked in' }}</td></tr>
        </table>
        @can('Record exam attendance')<form method="POST" action="{{ route('seating.verify.mark', $seat->code) }}" class="d-flex gap-2">@csrf<button name="mark" value="present" class="btn btn-success flex-fill">Present</button><button name="mark" value="absent" class="btn btn-outline-danger">Absent</button></form>@endcan
    </div></div>
</div></div></div>
@endsection
