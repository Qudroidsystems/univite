@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 d-print-none">
                <h4 class="mb-0">Graduation list</h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('graduation.index') }}" class="btn btn-light">Back</a>
                    @if ($list->isDraft())
                        @can('Generate graduation lists')
                            <form method="POST" action="{{ route('graduation.run') }}">@csrf<input type="hidden" name="programme_id" value="{{ $list->programme_id }}"><input type="hidden" name="academic_session_id" value="{{ $list->academic_session_id }}"><button class="btn btn-soft-primary" type="submit"><i class="ri-refresh-line me-1"></i>Check again</button></form>
                        @endcan
                        @can('Approve graduation lists')
                            <form method="POST" action="{{ route('graduation.approve', $list->id) }}" data-confirm="Approve this list? Every eligible student not held back will be marked Graduated. This cannot be undone from the portal.">@csrf<button class="btn btn-success" type="submit"><i class="ri-graduation-cap-line me-1"></i>Approve and graduate</button></form>
                        @endcan
                    @endif
                    <button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
                </div>
            </div>
            @include('academics.partials.alerts')

            <div class="card"><div class="card-body">
                @include('partials.letterhead', ['subtitle' => 'Graduation list · ' . ($list->session->name)])
                <table class="table table-sm mb-3">
                    <tr><th style="width:20%">Programme</th><td>{{ $list->programme->name }} ({{ $list->programme->degree_type }})</td><th style="width:15%">Final level</th><td>{{ $finalLevel }}</td></tr>
                    <tr><th>Department</th><td>{{ $list->programme->department->name }}, {{ $list->programme->department->faculty->name }}</td><th>Status</th><td>{{ $list->isDraft() ? 'Draft, checked ' . $list->checked_at?->format('j M Y, H:i') : 'Approved by ' . ($list->approver?->name ?? '—') . ', ' . $list->approved_at->format('j M Y') }}</td></tr>
                </table>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th>#</th><th>Matric</th><th>Name</th><th class="text-end">Units passed</th><th class="text-end">CGPA</th><th>Class</th><th>Outcome</th></tr></thead>
                        <tbody>
                        @forelse ($entries->values() as $i => $e)
                            <tr class="{{ $e->graduates() ? '' : 'text-muted' }}">
                                <td>{{ $i + 1 }}</td><td>{{ $e->student->matric_label }}</td><td>{{ $e->student->full_name }}</td>
                                <td class="text-end">{{ $e->units_passed }}</td><td class="text-end fw-semibold">{{ number_format($e->cgpa, 2) }}</td><td>{{ $e->degree_class ?? '—' }}</td>
                                <td>
                                    @if ($e->graduates())
                                        <span class="badge bg-success-subtle text-success">{{ $list->isDraft() ? 'Eligible' : 'Graduated' }}</span>
                                    @elseif ($e->is_excluded)
                                        <span class="badge bg-secondary-subtle text-secondary">Held back</span> <span class="fs-12">{{ $e->exclusion_note }}</span>
                                    @else
                                        <span class="badge bg-danger-subtle text-danger">Not yet</span>
                                        @foreach ($e->problems ?? [] as $p)<div class="fs-12">{{ $p }}</div>@endforeach
                                    @endif
                                    @if ($list->isDraft() && $e->is_eligible)
                                        @can('Generate graduation lists')
                                            <form method="POST" action="{{ route('graduation.exclude', $e->id) }}" class="d-flex gap-1 mt-1 d-print-none">
                                                @csrf
                                                @if ($e->is_excluded)
                                                    <input type="hidden" name="exclude" value="0"><button class="btn btn-sm btn-link p-0" type="submit">Put back on the list</button>
                                                @else
                                                    <input type="hidden" name="exclude" value="1"><input name="note" class="form-control form-control-sm" placeholder="Reason to hold back" required><button class="btn btn-sm btn-soft-secondary text-nowrap" type="submit">Hold back</button>
                                                @endif
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-5">No enrolled student of this programme is in its final level.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div></div>
        </div>
    </div>
</div>
@endsection
