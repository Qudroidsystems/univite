@extends('layouts.master')

@section('content')
@php $total = $rows->where('item.is_required', true)->count(); $done = $rows->where('item.is_required', true)->where('status', 'cleared')->count(); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">My clearance</h4>
                <a href="{{ route('dashboard') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')

            @if ($student->cleared_at)
                <div class="alert alert-success d-flex justify-content-between align-items-center">
                    <span><strong>You are fully cleared.</strong> {{ $student->matric_number ? 'Your matric number is ' . $student->matric_number . '.' : 'Your matric number will be issued soon.' }}</span>
                    <a href="{{ route('my.clearance.slip') }}" class="btn btn-sm btn-success"><i class="ri-printer-line me-1"></i>Clearance slip</a>
                </div>
            @else
                <p class="text-muted">{{ $done }} of {{ $total }} required items cleared. Automatic items update by themselves when you finish the step. Others are signed off by the office named under each item.</p>
            @endif

            @foreach ($rows as $row)
                @php $item = $row->item; @endphp
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <h6 class="mb-1">{{ $item->name }} @unless ($item->is_required)<span class="badge bg-light text-body border">Optional</span>@endunless</h6>
                                @if ($item->description)<div class="text-muted fs-13">{{ $item->description }}</div>@endif
                                @if ($row->status === 'rejected')<div class="mt-2 text-danger fs-13"><i class="ri-error-warning-line me-1"></i>{{ $row->note }}</div>@endif
                                @if ($row->status === 'pending' && $row->resubmitted_at)<div class="mt-2 text-muted fs-13">Sent back for another look on {{ $row->resubmitted_at->format('j M Y') }}.</div>@endif
                            </div>
                            <span class="badge {{ \App\Models\StudentClearance::BADGES[$row->status] }}">{{ \App\Models\StudentClearance::STATUSES[$row->status] }}</span>
                        </div>
                        @if ($row->status === 'rejected' && ! $item->isAuto())
                            <hr>
                            <form method="POST" action="{{ route('my.clearance.resubmit', $item->id) }}" class="d-flex flex-wrap gap-2">
                                @csrf
                                <input name="note" class="form-control" style="max-width:380px" maxlength="500" placeholder="What did you fix? (optional)">
                                <button class="btn btn-primary">Ask for another look</button>
                            </form>
                        @elseif ($row->status !== 'cleared' && $item->isAuto())
                            <div class="mt-2 fs-13">
                                @switch($item->auto_rule)
                                    @case('fees_paid')@case('acceptance_fee_paid')<a href="{{ route('my.fees') }}">Go to My Fees</a>@break
                                    @default<a href="{{ route('my.onboarding') }}">Go to Getting started</a>
                                @endswitch
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            @if ($notifications->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Updates</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($notifications as $n)
                            <li class="list-group-item"><div class="fw-semibold">{{ $n->data['title'] ?? '' }}</div><div class="fs-13">{{ $n->data['body'] ?? '' }}</div><div class="text-muted fs-12">{{ $n->created_at->diffForHumans() }}</div></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
