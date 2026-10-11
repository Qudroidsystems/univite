@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:860px">
            <div class="page-title-box d-flex align-items-center justify-content-between">
                <h4 class="mb-0">{{ $session->name }} <span class="badge bg-{{ \App\Services\SessionLifecycle::BADGES[$session->status] ?? 'secondary' }} ms-2">{{ $lifecycle->label($session->status) }}</span></h4>
                <a href="{{ route('academics.sessions.index') }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>Sessions</a>
            </div>
            @include('academics.partials.alerts')

            <div class="card"><div class="card-header"><h5 class="card-title mb-0">Move this session</h5></div>
                <div class="card-body">
                    <p class="text-muted">Draft → Setup → Active → Closing → Closed → Archived. A session is checked before it goes live, and every change is logged.</p>
                    @forelse ($options as $to => $o)
                        <form method="POST" action="{{ route('sessions.lifecycle.change', $session) }}" class="border rounded p-3 mb-3" @if ($o->needsReason) data-confirm="Change {{ $session->name }} to {{ $lifecycle->label($to) }}?" @endif>
                            @csrf <input type="hidden" name="to" value="{{ $to }}">
                            <div class="d-flex align-items-center justify-content-between mb-2"><strong>Move to {{ $lifecycle->label($to) }}</strong>
                                @can('Change session status')<button class="btn btn-sm btn-primary" type="submit" @disabled(! $o->allowed())>{{ $lifecycle->label($to) }}</button>@endcan</div>
                            @foreach ($o->problems as $p)<div class="text-danger small"><i class="ri-close-circle-line me-1"></i>{{ $p }}</div>@endforeach
                            @foreach ($o->warnings as $w)<div class="text-warning small"><i class="ri-error-warning-line me-1"></i>{{ $w }}</div>@endforeach
                            @if ($o->clean())<div class="text-success small"><i class="ri-checkbox-circle-line me-1"></i>All checks passed.</div>@endif
                            @if ($o->needsReason)<input type="text" name="reason" class="form-control form-control-sm mt-2" placeholder="Reason (required)" maxlength="500" required>@endif
                        </form>
                    @empty
                        <p class="mb-0 text-muted">There is nowhere further to move this session.</p>
                    @endforelse
                </div>
            </div>

            <div class="card"><div class="card-header"><h5 class="card-title mb-0">History</h5></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>When</th><th>Change</th><th>By</th><th>Reason</th></tr></thead>
                    <tbody>
                    @forelse ($history as $h)
                        <tr><td class="text-nowrap">{{ $h->created_at?->format('d M Y, g:i A') }}</td>
                            <td>{{ $h->from_status ? $lifecycle->label($h->from_status) : '—' }} → {{ $lifecycle->label($h->to_status) }}</td>
                            <td>{{ $h->user?->name ?? 'System' }}</td><td>{{ $h->reason ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center py-3">No changes recorded yet.</td></tr>
                    @endforelse
                    </tbody></table></div>
            </div>
        </div>
    </div>
</div>
@endsection
