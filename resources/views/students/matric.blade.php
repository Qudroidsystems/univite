@extends('layouts.master')

@section('content')
@php $u = auth()->user(); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row"><div class="col-12"><div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-hashtag me-1 align-middle"></i>Matric Numbers</h4>
                <div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li><li class="breadcrumb-item active">Matric Numbers</li></ol></div>
            </div></div></div>

            @include('academics.partials.alerts')

            <p class="text-muted">Numbers are issued after a student is fully cleared, using the <a href="{{ route('students.settings') }}">matric number format</a>. Generate them in a batch, set a special number by hand, bring in numbers made elsewhere, or cancel and undo.</p>

            <ul class="nav nav-pills mb-3">
                @foreach (['waiting' => 'Waiting for a number', 'issued' => 'Issued', 'cancelled' => 'Cancelled'] as $key => $label)
                    <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('matric.index', array_merge(request()->only('q', 'programme_id'), ['tab' => $key])) }}">{{ $label }} <span class="badge bg-light text-body ms-1">{{ $counts[$key] }}</span></a></li>
                @endforeach
            </ul>

            <div class="row">
                <div class="col-lg-9">
                    <div class="card">
                        <div class="card-header">
                            <form method="GET" class="d-flex flex-wrap gap-2">
                                <input type="hidden" name="tab" value="{{ $tab }}">
                                <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:220px" placeholder="Name or matric number">
                                <select name="programme_id" class="form-select" style="width:auto;max-width:240px" onchange="this.form.submit()"><option value="">All programmes</option>@foreach ($programmes as $id => $name)<option value="{{ $id }}" @selected((string) request('programme_id') === (string) $id)>{{ $name }}</option>@endforeach</select>
                                <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                            </form>
                        </div>

                        @if ($tab === 'waiting' && $u->can('Generate matric numbers'))
                            <form method="POST" action="{{ route('matric.generate') }}" id="gen-form">
                                @csrf
                                <input type="hidden" name="programme_id" value="{{ request('programme_id') }}">
                                <div class="card-body border-bottom d-flex flex-wrap align-items-center gap-2">
                                    @if ($preview)<span class="text-muted">Next number: <code>{{ $preview }}</code></span>@endif
                                    <button class="btn btn-primary ms-auto" type="submit" name="all" value="1" onclick="document.querySelectorAll('.pick').forEach(c => c.checked = false); return confirm('Generate numbers for everyone waiting{{ request('programme_id') ? ' in this programme' : '' }}?')"><i class="ri-magic-line me-1"></i>Generate for all waiting</button>
                                    <button class="btn btn-soft-primary" type="submit" onclick="return document.querySelectorAll('.pick:checked').length > 0 || (alert('Tick at least one student.'), false)">Generate for ticked</button>
                                </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light"><tr>@if ($tab === 'waiting' && $u->can('Generate matric numbers'))<th style="width:1%"></th>@endif<th>Student</th><th>Programme</th><th>{{ $tab === 'cancelled' ? 'Cancelled number' : 'Matric number' }}</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                    @forelse ($students as $s)
                                        @php $last = $s->matricEvents->first(); @endphp
                                        <tr>
                                            @if ($tab === 'waiting' && $u->can('Generate matric numbers'))<td><input type="checkbox" class="form-check-input pick" name="student_ids[]" value="{{ $s->id }}"></td>@endif
                                            <td><a href="{{ route('students.show', $s->id) }}" class="fw-semibold">{{ $s->full_name }}</a></td>
                                            <td>{{ $s->programme->name }}</td>
                                            <td>
                                                @if ($tab === 'cancelled')<span class="text-decoration-line-through">{{ $last?->matric_number }}</span><div class="text-muted fs-12">{{ $last?->note }}</div>
                                                @else{{ $s->matric_number ?: '—' }}@if ($last && $s->matric_number)<div class="text-muted fs-12">{{ ucfirst($last->action) }}{{ $last->batch ? ' · batch ' . $last->batch : '' }}</div>@endif @endif
                                            </td>
                                            <td class="text-end text-nowrap">
                                                @if ($tab === 'waiting' && $u->can('Assign special matric numbers'))
                                                    <button type="button" class="btn btn-sm btn-soft-secondary js-special" data-id="{{ $s->id }}" data-name="{{ $s->full_name }}">Set a number</button>
                                                @endif
                                                @if ($tab === 'issued' && $u->can('Cancel matric numbers'))
                                                    <button type="button" class="btn btn-sm btn-soft-danger js-cancel" data-id="{{ $s->id }}" data-name="{{ $s->full_name }}" data-number="{{ $s->matric_number }}">Cancel</button>
                                                @endif
                                                @if ($tab === 'cancelled')
                                                    @if ($u->can('Restore cancelled matric numbers'))<button form="restore-{{ $s->id }}" class="btn btn-sm btn-soft-success">Restore</button>@endif
                                                    @if ($u->can('Assign special matric numbers'))<button type="button" class="btn btn-sm btn-soft-secondary js-special" data-id="{{ $s->id }}" data-name="{{ $s->full_name }}">Set a number</button>@endif
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center text-muted py-5">{{ $tab === 'waiting' ? 'Nobody is waiting. Students appear here once they are fully cleared.' : 'Nothing here.' }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if ($tab === 'waiting' && $u->can('Generate matric numbers'))</form>@endif
                        @if ($students->hasPages())<div class="card-footer">{{ $students->links() }}</div>@endif
                    </div>
                    @if ($tab === 'cancelled')
                        @foreach ($students as $s)<form id="restore-{{ $s->id }}" method="POST" action="{{ route('matric.restore', $s->id) }}">@csrf</form>@endforeach
                    @endif
                </div>

                <div class="col-lg-3">
                    @can('Import external matric numbers')
                        <div class="card">
                            <div class="card-header"><h6 class="card-title mb-0">Bring in numbers made elsewhere</h6></div>
                            <div class="card-body">
                                <p class="text-muted fs-12">A CSV with two columns: application number (or JAMB number or email), then the matric number. Only fully cleared students are updated; rows that cannot be applied are listed.</p>
                                <form method="POST" action="{{ route('matric.import') }}" enctype="multipart/form-data">
                                    @csrf
                                    <input type="file" name="file" accept=".csv,.txt" class="form-control mb-2" required>
                                    <button class="btn btn-primary w-100" type="submit"><i class="ri-upload-2-line me-1"></i>Apply numbers</button>
                                </form>
                            </div>
                        </div>
                    @endcan

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Recent batches</h6></div>
                        <ul class="list-group list-group-flush">
                            @forelse ($batches as $b)
                                <li class="list-group-item d-flex justify-content-between align-items-center"><span><code>{{ $b->batch }}</code><div class="text-muted fs-12">{{ \Illuminate\Support\Carbon::parse($b->at)->format('j M Y, H:i') }}</div></span><span class="badge bg-light text-body">{{ $b->total }}</span></li>
                            @empty
                                <li class="list-group-item text-muted">No batches yet.</li>
                            @endforelse
                        </ul>
                        @can('Undo matric number batches')
                            <div class="card-body border-top">
                                <form method="POST" action="{{ route('matric.undo') }}" onsubmit="return confirm('Take back every number in this batch? Those students go back to waiting.')" class="d-flex gap-2">
                                    @csrf
                                    <input name="batch" class="form-control text-uppercase" placeholder="Batch code" maxlength="40" required>
                                    <button class="btn btn-soft-danger" type="submit">Undo</button>
                                </form>
                            </div>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="special-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form method="POST" id="special-form" class="modal-content">@csrf
        <div class="modal-header bg-light"><h5 class="modal-title">Set a matric number for <span id="special-name"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><label class="form-label">Matric number</label><input name="matric_number" class="form-control mb-3" maxlength="60" required><label class="form-label">Note (optional)</label><input name="note" class="form-control" maxlength="255"></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Assign</button></div>
    </form></div></div>
<div class="modal fade" id="cancel-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form method="POST" id="cancel-form" class="modal-content">@csrf
        <div class="modal-header bg-light"><h5 class="modal-title">Cancel <span id="cancel-number"></span> (<span id="cancel-name"></span>)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><p class="text-muted fs-13">The number is withdrawn and never issued again, but you can restore it later.</p><label class="form-label">Reason</label><input name="reason" class="form-control" maxlength="255" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep</button><button class="btn btn-danger">Cancel number</button></div>
    </form></div></div>
@push('scripts')
<script>
    const base = @json(url('matric'));
    document.querySelectorAll('.js-special').forEach(b => b.addEventListener('click', () => {
        document.getElementById('special-form').action = base + '/' + b.dataset.id + '/special';
        document.getElementById('special-name').textContent = b.dataset.name;
        new bootstrap.Modal(document.getElementById('special-modal')).show();
    }));
    document.querySelectorAll('.js-cancel').forEach(b => b.addEventListener('click', () => {
        document.getElementById('cancel-form').action = base + '/' + b.dataset.id + '/cancel';
        document.getElementById('cancel-name').textContent = b.dataset.name;
        document.getElementById('cancel-number').textContent = b.dataset.number;
        new bootstrap.Modal(document.getElementById('cancel-modal')).show();
    }));
</script>
@endpush
@endsection
