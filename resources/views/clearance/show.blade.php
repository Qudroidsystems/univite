@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:900px">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 class="mb-0">{{ $student->full_name }}</h4>
                    <div class="text-muted">{{ $student->programme->name }} · {{ $student->matric_label }}</div>
                </div>
                <div class="d-flex gap-2">
                    @if ($student->cleared_at)<a href="{{ route('clearance.slip', $student->id) }}" class="btn btn-soft-success"><i class="ri-printer-line me-1"></i>Clearance slip</a>@endif
                    <a href="{{ route('clearance.index') }}" class="btn btn-light">Back</a>
                </div>
            </div>
            @include('academics.partials.alerts')

            @if ($student->cleared_at)
                <div class="alert alert-success">Fully cleared on {{ $student->cleared_at->format('j M Y') }}.@if (! $student->matric_number) The matric number has not been issued yet.@endif</div>
            @endif

            @forelse ($rows as $row)
                @php $item = $row->item; $can = $service->canSign(auth()->user(), $item); @endphp
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <h6 class="mb-1">{{ $item->name }} @unless ($item->is_required)<span class="badge bg-light text-body border">Optional</span>@endunless</h6>
                                @if ($item->description)<div class="text-muted fs-13">{{ $item->description }}</div>@endif
                                <div class="text-muted fs-12 mt-1">{{ $item->isAuto() ? 'Checked automatically' : 'Signed by: ' . ($item->office_role ?: 'any clearance officer') }}</div>
                                @if ($row->note && $row->status !== 'pending' )<div class="mt-1 fs-13">{{ $row->note }}@if ($row->decider) <span class="text-muted">— {{ $row->decider->name }}, {{ $row->decided_at?->format('j M Y') }}</span>@endif</div>@endif
                                @if ($row->student_note && $row->status === 'pending')<div class="mt-1 fs-13"><span class="text-muted">Student says:</span> {{ $row->student_note }}</div>@endif
                            </div>
                            <span class="badge {{ \App\Models\StudentClearance::BADGES[$row->status] }}">{{ \App\Models\StudentClearance::STATUSES[$row->status] }}</span>
                        </div>

                        @if ($can)
                            <hr>
                            <form method="POST" action="{{ route('clearance.decide', [$student->id, $item->id]) }}" class="d-flex flex-wrap gap-2 align-items-center">
                                @csrf
                                <input name="note" class="form-control" style="max-width:340px" maxlength="500" placeholder="Remark (required when rejecting)">
                                <button name="status" value="cleared" class="btn btn-success"><i class="ri-check-line me-1"></i>Clear</button>
                                <button name="status" value="rejected" class="btn btn-soft-danger"><i class="ri-close-line me-1"></i>Reject</button>
                            </form>
                            @if ($row->status !== 'pending')
                                <form method="POST" action="{{ route('clearance.reset', [$student->id, $item->id]) }}" class="mt-2" onsubmit="return confirm('Put this item back to pending?')">@csrf<button class="btn btn-sm btn-link p-0">Undo this decision</button></form>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <div class="card"><div class="card-body text-center text-muted py-5">No clearance items are switched on. Add them under Academic Setup → Clearance Items.</div></div>
            @endforelse
        </div>
    </div>
</div>
@endsection
