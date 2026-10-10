@extends('layouts.master')

@section('content')
@php $canEdit = auth()->user()->can('Edit programme curriculum'); @endphp

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0"><i class="ri-node-tree me-1 align-middle"></i>Curriculum</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">Curriculum</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <p class="text-muted mb-3">Choose a programme, then place courses into each level and semester. Credit units are totalled per semester and checked against the programme's credit-load limits (electives are not counted towards the minimum).</p>

            <div class="card">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label">Programme</label>
                            <select name="programme" class="form-select" onchange="this.form.submit()">
                                <option value="">Select a programme…</option>
                                @foreach ($programmes->groupBy(fn ($p) => $p->department->faculty->name) as $faculty => $group)
                                    <optgroup label="{{ $faculty }}">
                                        @foreach ($group as $p)
                                            <option value="{{ $p->id }}" @selected($programme?->id === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        @if ($programme && $canEdit)
                            <div class="col-md-4 text-md-end">
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#add-course-modal"><i class="ri-add-line me-1"></i>Add courses</button>
                            </div>
                        @endif
                    </form>
                </div>
            </div>

            @if (! $programme)
                <div class="text-center text-muted py-5"><i class="ri-node-tree fs-1 d-block mb-2"></i>Pick a programme to see and edit its curriculum.</div>
            @else
                <div class="alert alert-info py-2">
                    <strong>{{ $programme->name }}</strong> · {{ $programme->degree_type }} · {{ $programme->duration_years }} year(s) ·
                    credit load per semester as shown on each card (set under <a href="{{ route('academics.rules.index', ['scope' => 'programme', 'id' => $programme->id]) }}">Academic Rules</a>)
                </div>

                @foreach ($grid as $level => $semesters)
                    <h5 class="mt-4 mb-2">{{ $programme->levelLabel((int) $level) }}</h5>
                    <div class="row">
                        @foreach ($semesters as $order => $sem)
                            @php
                                $low  = $sem['entries']->isNotEmpty() && $sem['units'] < $sem['min'];
                                $high = $sem['all'] > $sem['max'];
                            @endphp
                            <div class="col-xl-6">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="card-title mb-0">{{ $sem['label'] }}</h6>
                                        <span>
                                            <span class="text-muted fs-12 me-1">{{ $sem['min'] }}–{{ $sem['max'] }} allowed</span><span class="badge {{ $high ? 'bg-danger' : ($low ? 'bg-warning' : 'bg-success-subtle text-success') }}">{{ $sem['units'] }} units</span>
                                            @if ($sem['all'] !== $sem['units'])
                                                <span class="badge bg-light text-body border">{{ $sem['all'] }} incl. electives</span>
                                            @endif
                                        </span>
                                    </div>
                                    @if ($low) <div class="px-3 pt-2 text-warning fs-12">Below the minimum of {{ $sem['min'] }} units.</div> @endif
                                    @if ($high) <div class="px-3 pt-2 text-danger fs-12">Above the maximum of {{ $sem['max'] }} units.</div> @endif
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <tbody>
                                                @forelse ($sem['entries'] as $e)
                                                    <tr>
                                                        <td style="width:90px"><span class="badge bg-primary-subtle text-primary">{{ $e->course->code }}</span></td>
                                                        <td>{{ $e->course->title }}</td>
                                                        <td class="text-nowrap"><span class="badge bg-light text-body border">{{ $statuses[$e->status] }}</span></td>
                                                        <td class="text-end text-nowrap">{{ $e->course->credit_units }} u</td>
                                                        @if ($canEdit)
                                                            <td class="text-end text-nowrap">
                                                                <button type="button" class="btn btn-sm btn-soft-primary btn-move" title="Move / change status"
                                                                        data-url="{{ route('academics.curriculum.update', $e->id) }}"
                                                                        data-title="{{ $e->course->code }} — {{ $e->course->title }}"
                                                                        data-level="{{ $e->level }}" data-semester="{{ $e->semester_order }}" data-status="{{ $e->status }}"><i class="ri-pencil-line"></i></button>
                                                                <form method="POST" action="{{ route('academics.curriculum.destroy', $e->id) }}" class="d-inline" onsubmit="return confirm('Remove this course from the curriculum?')">
                                                                    @csrf @method('DELETE')
                                                                    <button class="btn btn-sm btn-soft-danger" title="Remove"><i class="ri-delete-bin-line"></i></button>
                                                                </form>
                                                            </td>
                                                        @endif
                                                    </tr>
                                                @empty
                                                    <tr><td class="text-muted text-center py-3">No courses yet.</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>

@if ($programme && $canEdit)
    {{-- Add courses --}}
    <div class="modal fade" id="add-course-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form method="POST" action="{{ route('academics.curriculum.store') }}" class="modal-content">
                @csrf
                <input type="hidden" name="programme_id" value="{{ $programme->id }}">
                <div class="modal-header bg-light">
                    <h5 class="modal-title">Add courses to {{ $programme->name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Level <span class="text-danger">*</span></label>
                            <select name="level" class="form-select" required>
                                @foreach ($programme->levels() as $l) <option value="{{ $l }}" @selected((string) old('level') === (string) $l)>{{ $programme->levelLabel((int) $l) }}</option> @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select name="semester_order" class="form-select" required>
                                @foreach (\App\Models\SemesterType::options() as $o => $l) <option value="{{ $o }}" @selected((string) old('semester_order') === (string) $o)>{{ $l }}</option> @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select" required>
                                @foreach ($statuses as $v => $l) <option value="{{ $v }}">{{ $l }}</option> @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Courses <span class="text-danger">*</span> <span class="text-muted fs-12">(hold Ctrl/Cmd to select several)</span></label>
                            <input type="search" class="form-control mb-2" id="course-filter" placeholder="Filter by code or title…">
                            <select name="course_ids[]" id="course-select" class="form-select" multiple size="10" required>
                                @foreach ($courseOptions->groupBy(fn ($c) => $c->department->name) as $dept => $courses)
                                    <optgroup label="{{ $dept }}">
                                        @foreach ($courses as $c)
                                            <option value="{{ $c->id }}">{{ $c->code }} — {{ $c->title }} ({{ $c->credit_units }}u)</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @if ($courseOptions->isEmpty()) <div class="form-text text-warning">Every active course is already in this curriculum, or no courses exist yet.</div> @endif
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add to curriculum</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Move / change status --}}
    <div class="modal fade" id="move-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="" class="modal-content" id="move-form">
                @csrf @method('PUT')
                <div class="modal-header bg-light">
                    <h5 class="modal-title" id="move-title">Edit entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Level</label>
                        <select name="level" id="move-level" class="form-select">
                            @foreach ($programme->levels() as $l) <option value="{{ $l }}">{{ $programme->levelLabel((int) $l) }}</option> @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Semester</label>
                        <select name="semester_order" id="move-semester" class="form-select">
                            @foreach (\App\Models\SemesterType::options() as $o => $l) <option value="{{ $o }}">{{ $l }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" id="move-status" class="form-select">
                            @foreach ($statuses as $v => $l) <option value="{{ $v }}">{{ $l }}</option> @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
    (function () {
        const moveModal = new bootstrap.Modal(document.getElementById('move-modal'));
        document.querySelectorAll('.btn-move').forEach(btn => btn.addEventListener('click', () => {
            document.getElementById('move-form').action = btn.dataset.url;
            document.getElementById('move-title').textContent = btn.dataset.title;
            document.getElementById('move-level').value = btn.dataset.level;
            document.getElementById('move-semester').value = btn.dataset.semester;
            document.getElementById('move-status').value = btn.dataset.status;
            moveModal.show();
        }));

        document.getElementById('course-filter')?.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('#course-select option').forEach(o => {
                o.hidden = q !== '' && !o.textContent.toLowerCase().includes(q);
            });
        });

        @if ($errors->any() || session('error'))
            new bootstrap.Modal(document.getElementById('add-course-modal')).show();
        @endif
    })();
    </script>
    @endpush
@endif
@endsection
