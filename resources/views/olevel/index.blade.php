@extends('layouts.master')

@section('content')
@php use App\Models\StudentOlevelSitting as S; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div><h4 class="mb-0"><i class="ri-file-list-3-line me-1"></i>O Level verification</h4><div class="text-muted fs-13">Compare each student's grades with their uploaded result and confirm them before course registration.</div></div>
        <a href="{{ route('olevel.requirements') }}" class="btn btn-soft-primary btn-sm"><i class="ri-list-settings-line me-1"></i>Department requirements</a>
    </div>
    @include('academics.partials.alerts')

    <ul class="nav nav-pills mb-3 gap-1">
        @foreach (['submitted' => 'Awaiting confirmation', 'rejected' => 'Returned', 'confirmed' => 'Confirmed', 'draft' => 'Not submitted', 'all' => 'All'] as $k => $l)
            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['status' => $k, 'page' => null]) }}">{{ $l }} @if ($k !== 'all')<span class="badge bg-light text-body ms-1">{{ $counts[$k] ?? 0 }}</span>@endif</a></li>
        @endforeach
    </ul>

    <form method="GET" class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <input type="hidden" name="status" value="{{ $status }}">
        <div><label class="form-label mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($departments as $v => $l)<option value="{{ $v }}" @selected((string) request('department_id') === (string) $v)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="form-label mb-1">Programme</label><select name="programme_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($programmes as $v => $l)<option value="{{ $v }}" @selected((string) request('programme_id') === (string) $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="flex-grow-1"><label class="form-label mb-1">Student</label><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name or matric number"></div>
        <button class="btn btn-primary btn-sm">Filter</button>
    </div></form>

    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Student</th><th>Sitting</th><th>Subjects</th><th>Read from upload</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($rows as $s)
            @php $mism = $s->results->filter->mismatch()->count(); @endphp
            <tr>
                <td><span class="fw-semibold">{{ $s->student->full_name }}</span><div class="fs-12 text-muted">{{ $s->student->matric_number ?? $s->student->provisional_matric }} · {{ $s->student->programme?->name }}</div></td>
                <td>{{ $s->label() }}<div class="fs-12">@if ($s->document_path)<a href="{{ route('olevel.document', $s->id) }}" target="_blank"><i class="ri-attachment-2"></i> {{ \Illuminate\Support\Str::limit($s->document_name, 24) }}</a>@else<span class="text-danger">No upload</span>@endif</div></td>
                <td>{{ $s->results->whereNotNull('grade')->count() }}</td>
                <td>@switch($s->extraction_status)
                        @case('done') @if ($mism)<span class="badge bg-warning-subtle text-warning">{{ $mism }} {{ \Illuminate\Support\Str::plural('difference', $mism) }}</span>@else<span class="badge bg-success-subtle text-success">Matches</span>@endif @break
                        @case('failed') <span class="badge bg-danger-subtle text-danger">Could not read</span> @break
                        @default <span class="text-muted fs-12">Not read</span>
                    @endswitch</td>
                <td><span class="badge {{ S::BADGES[$s->status] }}">{{ S::STATUSES[$s->status] }}</span>@if ($s->reviewed_at)<div class="fs-12 text-muted">{{ $s->reviewer?->name }}, {{ $s->reviewed_at->format('j M') }}</div>@endif</td>
                <td class="text-end"><a href="{{ route('olevel.show', $s->id) }}" class="btn btn-sm btn-soft-primary">{{ $s->status === 'submitted' ? 'Check' : 'Open' }}</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">Nothing here.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="card-footer">{{ $rows->links() }}</div></div>
</div></div></div>
@endsection
