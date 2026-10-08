@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row"><div class="col-12"><div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-checkbox-circle-line me-1 align-middle"></i>Clearance</h4>
                <div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li><li class="breadcrumb-item active">Clearance</li></ol></div>
            </div></div></div>

            @include('academics.partials.alerts')

            <p class="text-muted">Students who have been physically verified are cleared here. Automatic items tick themselves; open a student to sign off the items that belong to your office. When every required item is cleared the student can get a matric number.</p>

            <ul class="nav nav-pills mb-3">
                @foreach (['pending' => 'Still clearing', 'cleared' => 'Fully cleared'] as $key => $label)
                    <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('clearance.index', array_merge(request()->only('q', 'batch', 'item'), ['tab' => $key])) }}">{{ $label }} <span class="badge bg-light text-body ms-1">{{ $counts[$key] }}</span></a></li>
                @endforeach
            </ul>

            <div class="card">
                <div class="card-header">
                    <form method="GET" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:220px" placeholder="Matric number or name">
                        <select name="item" class="form-select" style="width:auto" onchange="this.form.submit()">
                            <option value="">Any outstanding item</option>@foreach ($items as $i)<option value="{{ $i->id }}" @selected($itemId === $i->id)>Waiting on: {{ $i->name }}</option>@endforeach
                        </select>
                        <select name="batch" class="form-select" style="width:auto" onchange="this.form.submit()">
                            <option value="">All batches</option>@foreach ($batches as $b)<option value="{{ $b }}" @selected(request('batch') === $b)>{{ $b }}</option>@endforeach
                        </select>
                        <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                    </form>
                </div>

                <form method="POST" action="{{ route('clearance.bulk') }}" id="bulk-form">
                    @csrf
                    @if ($signable->isNotEmpty() && $tab === 'pending')
                        <div class="card-body border-bottom d-flex flex-wrap gap-2 align-items-center">
                            <span class="text-muted">With the ticked students:</span>
                            <select name="item_id" class="form-select" style="width:auto" required>
                                @foreach ($signable as $i)<option value="{{ $i->id }}">Clear: {{ $i->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-success" type="submit" onclick="return confirm('Clear this item for every ticked student?')"><i class="ri-check-double-line me-1"></i>Clear ticked</button>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr>@if ($signable->isNotEmpty() && $tab === 'pending')<th style="width:1%"></th>@endif<th>Student</th><th>Programme</th><th>Items</th><th>Matric number</th><th class="text-end"></th></tr></thead>
                            <tbody>
                                @forelse ($students as $s)
                                    @php $byItem = $s->clearances->keyBy('clearance_item_id'); $done = $items->filter(fn ($i) => ($byItem[$i->id]->status ?? null) === 'cleared')->count(); @endphp
                                    <tr>
                                        @if ($signable->isNotEmpty() && $tab === 'pending')<td><input type="checkbox" class="form-check-input" name="student_ids[]" value="{{ $s->id }}"></td>@endif
                                        <td><a href="{{ route('clearance.show', $s->id) }}" class="fw-semibold">{{ $s->full_name }}</a>@if ($s->admission_batch)<div class="text-muted fs-12">{{ $s->admission_batch }}</div>@endif</td>
                                        <td>{{ $s->programme->name }}</td>
                                        <td>
                                            <div class="fs-12 mb-1">{{ $done }} of {{ $items->count() }} cleared</div>
                                            @foreach ($items as $i)
                                                @php $st = $byItem[$i->id]->status ?? 'pending'; @endphp
                                                <span class="badge {{ \App\Models\StudentClearance::BADGES[$st] }}" title="{{ $i->name }}: {{ \App\Models\StudentClearance::STATUSES[$st] }}">{{ $i->name }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $s->matric_number ?: '—' }}</td>
                                        <td class="text-end"><a href="{{ route('clearance.show', $s->id) }}" class="btn btn-sm btn-soft-primary">Open</a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-5">No students here.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>
                @if ($students->hasPages())<div class="card-footer">{{ $students->links() }}</div>@endif
            </div>
        </div>
    </div>
</div>
@endsection
