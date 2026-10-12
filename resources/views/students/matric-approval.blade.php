@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex justify-content-between align-items-center"><h4 class="mb-0">Matric batch {{ $batch->code }}</h4>
                <a href="{{ route('matric.approvals.index') }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>All batches</a></div>
            @include('academics.partials.alerts')
            <div class="card"><div class="card-body">
                <span class="badge {{ \App\Models\MatricBatch::BADGES[$batch->status] }} mb-2">{{ \App\Models\MatricBatch::STATUSES[$batch->status] }}</span>
                <div class="text-muted fs-13">Prepared by {{ $batch->creator?->name ?? 'the system (automatic)' }} on {{ $batch->created_at->format('j M Y, g:i A') }}.
                    @if ($batch->decided_at) {{ $batch->status === 'approved' ? 'Approved' : 'Rejected' }} by {{ $batch->decider?->name }} on {{ $batch->decided_at->format('j M Y, g:i A') }}{{ $batch->decision_note ? ': ' . $batch->decision_note : '' }}.@endif</div>
            </div></div>

            @if ($batch->status === 'pending')
                @if ($problems)<div class="alert alert-warning">{{ $problems }} number(s) cannot be published and will be released if the batch is approved. The rest are not affected.</div>
                @else<div class="alert alert-success">Checked: no duplicates, every number is free and every student is still enrolled.</div>@endif
                <div class="card"><div class="table-responsive" style="max-height:520px"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Student</th><th>Programme</th><th>Reserved number</th><th>Check</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr><td>{{ $r['student']->full_name }}</td><td>{{ $r['student']->programme?->name }}</td><td><code>{{ $r['number'] }}</code></td>
                            <td>@if ($r['problem'])<span class="text-danger fs-13">{{ $r['problem'] }}</span>@else<span class="text-success fs-13">OK</span>@endif</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No numbers are waiting in this batch.</td></tr>
                    @endforelse
                    </tbody></table></div></div>

                @can('Approve matric numbers')
                    @if ($ownBatch)
                        <div class="alert alert-info">You prepared this batch, so someone else must approve it.</div>
                    @elseif ($rows->isNotEmpty())
                        <div class="row g-3">
                            <div class="col-md-7"><form method="POST" action="{{ route('matric.approvals.approve', $batch) }}" class="card h-100"><div class="card-body">
                                @csrf
                                <h6>Approve and publish</h6>
                                <input name="note" class="form-control mb-2" maxlength="500" placeholder="Approval reference, e.g. Senate meeting of 12 Oct 2026 (optional)">
                                <div class="form-check mb-3"><input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="confirm" required><label class="form-check-label" for="confirm">I have checked the {{ $rows->count() - $problems }} number(s) above. Publish them to the students now.</label></div>
                                <button class="btn btn-success">Approve and publish</button>
                            </div></form></div>
                            <div class="col-md-5"><form method="POST" action="{{ route('matric.approvals.reject', $batch) }}" class="card h-100"><div class="card-body">
                                @csrf
                                <h6>Reject</h6>
                                <textarea name="reason" rows="2" class="form-control mb-2 @error('reason') is-invalid @enderror" required minlength="10" placeholder="Why the batch is rejected">{{ old('reason') }}</textarea>
                                @error('reason')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                                <button class="btn btn-soft-danger">Reject and release the numbers</button>
                            </div></form></div>
                        </div>
                    @endif
                @endcan
            @else
                <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Student</th><th>Number</th><th>Now holds</th></tr></thead>
                    <tbody>@foreach ($history as $e)<tr><td>{{ $e->student?->full_name }}</td><td><code>{{ $e->matric_number }}</code></td><td>{{ $e->student?->matric_number ?? '—' }}</td></tr>@endforeach</tbody>
                </table></div></div>
            @endif
        </div>
    </div>
</div>
@endsection
