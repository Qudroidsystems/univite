@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">{{ $pagetitle }}</h4></div>
            @include('academics.partials.alerts')
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-pills mb-2">
                        @foreach (['submitted' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Returned', 'draft' => 'Drafts', 'all' => 'All'] as $k => $l)
                            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ route('registrations.index', array_merge(request()->query(), ['status' => $k, 'page' => 1])) }}">{{ $l }} @if ($k !== 'all')<span class="badge bg-light text-body ms-1">{{ $counts[$k] ?? 0 }}</span>@endif</a></li>
                        @endforeach
                    </ul>
                    <form method="GET" class="d-flex gap-2 flex-wrap">
                        <input type="hidden" name="status" value="{{ $status }}">
                        <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:220px" placeholder="Name or matric…">
                        <select name="programme_id" class="form-select" style="width:auto;max-width:260px" onchange="this.form.submit()"><option value="">All programmes</option>@foreach ($programmes as $id => $n)<option value="{{ $id }}" @selected((string) request('programme_id') === (string) $id)>{{ $n }}</option>@endforeach</select>
                        <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                    </form>
                </div>
                <form method="POST" action="{{ route('registrations.bulk') }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th style="width:1%"></th><th>Student</th><th>Programme</th><th>Semester</th><th>Units</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($forms as $f)
                                <tr>
                                    <td>@if ($f->status === 'submitted')<input type="checkbox" class="form-check-input" name="forms[]" value="{{ $f->id }}">@endif</td>
                                    <td><span class="fw-semibold">{{ $f->student->full_name }}</span><div class="text-muted fs-12">{{ $f->student->matric_label }}</div></td>
                                    <td>{{ $f->student->programme->name }}<div class="text-muted fs-12">{{ $f->level }}L</div></td>
                                    <td>{{ $f->session->name }} · {{ $f->semesterType->name }}</td>
                                    <td>{{ $f->total_units }}</td>
                                    <td><span class="badge {{ \App\Models\RegistrationForm::BADGES[$f->status] }}">{{ \App\Models\RegistrationForm::STATUSES[$f->status] }}</span></td>
                                    <td class="text-end"><a class="btn btn-sm btn-soft-primary" href="{{ route('registrations.show', $f->id) }}">Open</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-5">No registration forms here.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        @if ($adviser || auth()->user()->can('Approve course registrations'))<button class="btn btn-success btn-sm" type="submit" data-confirm="Approve every ticked registration?"><i class="ri-check-double-line me-1"></i>Approve ticked</button>@else<span></span>@endif
                        {{ $forms->links() }}
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
