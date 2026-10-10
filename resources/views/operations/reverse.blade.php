@extends('layouts.master')

@section('content')
@php $badge = ['change' => 'bg-primary-subtle text-primary', 'skip' => 'bg-secondary-subtle text-secondary', 'blocked' => 'bg-danger-subtle text-danger']; $word = ['change' => 'Will change', 'skip' => 'Skipped', 'blocked' => 'Blocked']; @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:1000px">
            <div class="page-title-box d-flex justify-content-between align-items-center">
                <h4 class="mb-0">{{ $title }}</h4>
                <a href="{{ route('operations.show', $batch) }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>Operation {{ $batch->operation_id }}</a>
            </div>
            @include('academics.partials.alerts')

            <div class="card"><div class="card-body">
                <div class="text-muted fs-12 text-uppercase mb-1">Original operation</div>
                <div class="fw-medium">{{ $batch->label() }} · {{ $batch->operation_id }}</div>
                <div class="text-muted fs-13">By {{ $batch->user?->name ?? 'the system' }} on {{ $batch->created_at->format('j M Y, g:i A') }}{{ $batch->session ? ' · ' . $batch->session->name : '' }}{{ $batch->reason ? ' · Reason: ' . $batch->reason : '' }}</div>
                <div class="text-muted fs-12 mt-2">Nothing has been changed yet. This page only shows what would happen.</div>
            </div></div>

            @if (count($plan->modes) > 1)
                <ul class="nav nav-pills mb-3">
                    @foreach ($plan->modes as $key => [$label, $help])
                        <li class="nav-item"><a class="nav-link {{ $plan->mode === $key ? 'active' : '' }}" href="{{ route('operations.reverse', [$batch, 'mode' => $key]) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            @endif
            <p class="text-muted">{{ $plan->modes[$plan->mode][1] }}</p>

            <div class="row g-3 mb-3">
                <div class="col-sm-4"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Will change</div><div class="fs-3 fw-semibold text-primary">{{ $counts['change'] }}</div></div></div></div>
                <div class="col-sm-4"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Skipped (already changed since)</div><div class="fs-3 fw-semibold">{{ $counts['skip'] }}</div></div></div></div>
                <div class="col-sm-4"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Blocked (in use elsewhere)</div><div class="fs-3 fw-semibold text-danger">{{ $counts['blocked'] }}</div></div></div></div>
            </div>

            @foreach ($plan->blockers as $b)<div class="alert alert-danger">{{ $b }}</div>@endforeach
            @foreach ($plan->warnings as $w)<div class="alert alert-warning">{{ $w }}</div>@endforeach
            @if ($plan->outcome)<div class="alert alert-info"><strong>Expected outcome:</strong> {{ $plan->outcome }}</div>@endif

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Records affected ({{ count($plan->items) }})</h6></div>
                <div class="table-responsive" style="max-height:480px"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Record</th><th>Now</th><th>After</th><th>Outcome</th></tr></thead>
                    <tbody>
                    @forelse ($plan->items as $i)
                        <tr><td>{{ $i['label'] }}</td><td>{{ $i['from'] ?? '—' }}</td><td>{{ $i['to'] ?? '—' }}</td>
                            <td><span class="badge {{ $badge[$i['outcome']] }}">{{ $word[$i['outcome']] }}</span>@if ($i['why'])<div class="text-muted fs-12">{{ $i['why'] }}</div>@endif</td></tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No records.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>

            @if ($plan->canRun())
                <form method="POST" action="{{ route('operations.reverse.run', $batch) }}" class="card">
                    @csrf
                    <input type="hidden" name="mode" value="{{ $plan->mode }}"><input type="hidden" name="fingerprint" value="{{ $plan->fingerprint() }}">
                    <div class="card-body">
                        <label class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea name="reason" rows="2" class="form-control @error('reason') is-invalid @enderror" required minlength="10" maxlength="500" placeholder="For example: numbers were generated with the wrong programme code.">{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-check mt-3">
                            <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox" name="confirm" value="1" id="confirm" required>
                            <label class="form-check-label" for="confirm">I have checked the {{ $counts['change'] }} record(s) above. Record this as a new operation linked to {{ $batch->operation_id }}.</label>
                            @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-danger" type="submit">{{ $plan->modes[$plan->mode][0] }} {{ $counts['change'] }} record(s)</button></div>
                </form>
            @else
                <div class="alert alert-secondary">Nothing can be changed this way right now.</div>
            @endif
        </div>
    </div>
</div>
@endsection
