@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Leave approvals', 'icon' => 'ri-inbox-line', 'subtitle' => 'Applications waiting for your decision, oldest reminders first.'])
    @include('academics.partials.alerts')

    <div class="card"><div class="card-header"><h6 class="card-title mb-0">Waiting for you ({{ $waiting->count() }})</h6></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Staff</th><th>Leave</th><th>Dates</th><th>Step</th><th>Notes</th><th style="width:1%" class="text-nowrap">Last changed by</th><th class="text-end">Decide</th></tr></thead>
            <tbody>
            @forelse ($waiting as $r)
                @php $s = $r->currentStep(); @endphp
                <tr><td><a href="{{ route('leave.show', $r) }}" class="fw-semibold">{{ $r->user->name }}</a><div class="text-muted fs-12">{{ $r->reference }}</div></td>
                    <td>{{ $r->type->name }}</td><td>{{ $r->start_date->format('j M') }} – {{ $r->end_date->format('j M Y') }}<div class="text-muted fs-12">{{ $r->days }} day(s)</div></td>
                    <td>{{ $s?->label }}@if ($s?->due_at)<div class="fs-12 {{ $s->due_at->isPast() ? 'text-danger' : 'text-muted' }}">due {{ $s->due_at->format('j M') }}</div>@endif</td>
                    <td class="fs-12">@foreach ($r->warnings ?? [] as $w)<div class="text-warning">{{ Str::limit($w, 90) }}</div>@endforeach @if ($r->documents()->exists())<div><i class="ri-attachment-line"></i> document attached</div>@endif</td>
                    <td><x-last-change :record="$r" :among="$waiting" /></td>
                    <td class="text-end"><form method="POST" action="{{ route('leave.decide', $r) }}" class="d-inline">@csrf<input type="hidden" name="back" value="approvals"><button name="action" value="approve" class="btn btn-sm btn-success">Approve</button></form>
                        <a href="{{ route('leave.show', $r) }}" class="btn btn-sm btn-light">Open</a></td></tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Nothing is waiting for you.</td></tr>
            @endforelse
            </tbody></table></div></div>

    @if ($decided->isNotEmpty())
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">You decided recently</h6></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
                @foreach ($decided as $r)
                    <tr><td><a href="{{ route('leave.show', $r) }}">{{ $r->reference }}</a></td><td>{{ $r->user->name }}</td><td>{{ $r->type->name }}</td><td>{{ $r->start_date->format('j M Y') }}</td><td><span class="badge {{ \App\Models\LeaveRequest::BADGES[$r->status] }}">{{ $r->phase }}</span></td>
                        <td><x-last-change :record="$r" :among="$decided" /></td></tr>
                @endforeach
            </tbody></table></div></div>
    @endif
</div></div></div>
@endsection
