@extends('layouts.master')

@section('content')
@php
    $icons = \App\Services\Setup\SetupSnapshots::ICONS;
    $changes = collect($plans)->sum(fn ($p) => $p->changes());
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h4 class="mb-0">Restore setup</h4>
                <div class="d-flex gap-2">
                    <a href="{{ route('setup.snapshots.index', array_filter(['session' => $snap->academic_session_id])) }}" class="btn btn-light"><i class="ri-arrow-left-line me-1"></i>All snapshots</a>
                    <a href="{{ route('setup.snapshots.download', $snap) }}" class="btn btn-soft-secondary"><i class="ri-download-2-line me-1"></i>Download</a>
                </div>
            </div>
            @include('academics.partials.alerts')

            <div class="uv-period-bar mb-3">
                <div class="text-white-75 fs-12 text-uppercase fw-semibold mb-1">{{ $snap->kind === 'auto' ? 'Automatic snapshot' : 'Snapshot' }} · {{ $snap->session?->name ?? 'University-wide' }}</div>
                <h5><i class="ri-camera-line me-1"></i>{{ $snap->label }}</h5>
                <div class="text-white-75 fs-13 mt-1">Saved {{ $snap->created_at->format('d M Y, g:i A') }} by {{ $snap->creator?->name ?? 'the system' }}.
                    @if ($madeBy) Saved before operation <a href="{{ route('operations.show', $madeBy) }}" class="text-white text-decoration-underline">{{ $madeBy->operation_id }}</a> changed anything. @endif
                    {{ $snap->note }}</div>
            </div>

            @if ($problem)
                <div class="alert alert-danger"><i class="ri-error-warning-line me-1"></i>{{ $problem }}</div>
            @else
                <form method="POST" action="{{ route('setup.snapshots.restore', $snap) }}" class="card" id="restore-form"
                      data-confirm="Restore the ticked parts of “{{ $snap->label }}”? What is there now is saved as a snapshot first.">
                    @csrf
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                        <h5 class="card-title mb-0"><i class="ri-eye-line me-1 text-primary"></i>What restoring would change</h5>
                        <span class="uv-count {{ $changes ? 'is-change' : '' }}">{{ $changes ? $changes . ' change(s) in all' : 'Everything already matches' }}</span>
                    </div>
                    @foreach ($snap->sections ?? [] as $k)
                        @php $plan = $plans[$k] ?? null; $sec = $sections[$k] ?? null; @endphp
                        @continue(! $sec)
                        <label class="uv-plan-row mb-0" style="cursor:pointer">
                            <span class="d-flex gap-2 align-items-start"><input class="form-check-input mt-1" type="checkbox" name="sections[]" value="{{ $k }}" @checked(in_array($k, $chosen, true) && $plan?->changes()) @disabled(! $plan?->changes())>
                                <i class="{{ $icons[$k] ?? 'ri-settings-3-line' }} uv-pick-icon"></i></span>
                            <span><strong>{{ $sec->label() }}</strong> <span class="text-muted fs-12">· {{ $snap->summary[$k] ?? 0 }} saved</span>
                                <span class="d-block text-muted fs-13">{{ $sec->about() }}</span>
                                @foreach ($plan?->kept ?? [] as $n)<span class="uv-note d-block"><i class="ri-information-line"></i>{{ $n }}</span>@endforeach</span>
                            <span class="d-flex flex-wrap gap-1 justify-content-end">
                                @if (! $plan?->changes())<span class="uv-count">Already the same</span>@endif
                                @if ($plan?->added)<span class="uv-count is-add">{{ $plan->added }} added back</span>@endif
                                @if ($plan?->updated)<span class="uv-count is-change">{{ $plan->updated }} changed back</span>@endif
                                @if ($plan?->removed)<span class="uv-count is-remove">{{ $plan->removed }} removed</span>@endif
                            </span>
                        </label>
                    @endforeach
                    @can('Manage setup snapshots')
                        <div class="card-footer">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-8"><label class="form-label">Why is the setup being restored?</label>
                                    <input type="text" name="reason" class="form-control @error('reason') is-invalid @enderror" value="{{ old('reason') }}" minlength="5" maxlength="500" placeholder="e.g. The grading change was made by mistake" required>
                                    @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror @error('sections')<div class="text-danger fs-13">{{ $message }}</div>@enderror</div>
                                <div class="col-md-4 text-md-end"><button class="btn btn-primary w-100" type="submit" @disabled(! $changes)><i class="ri-history-line me-1"></i>Restore the ticked parts</button></div>
                            </div>
                            <div class="text-muted fs-13 mt-2"><i class="ri-shield-check-line me-1 text-success"></i>Anything that is in use (a bill with invoices, a lecturer with a score sheet, an approval step with sheets waiting) is kept and listed above. Students, scores, results and payments are never changed.</div>
                        </div>
                    @endcan
                </form>
            @endif

            @if ($restores->isNotEmpty())
                <div class="card"><div class="card-header"><h5 class="card-title mb-0">Times this snapshot was restored</h5></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Operation</th><th>When / by</th><th>Reason</th><th>Changes</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($restores as $b)
                            <tr><td><a href="{{ route('operations.show', $b) }}" class="fw-semibold">{{ $b->operation_id }}</a></td>
                                <td class="text-nowrap">{{ $b->created_at->format('d M Y, g:i A') }}<div class="text-muted small">{{ $b->user?->name }}</div></td>
                                <td>{{ $b->reason }}</td><td>{{ $b->success_count }}{{ $b->skipped_count ? ', ' . $b->skipped_count . ' kept' : '' }}</td>
                                <td class="text-end">@if (isset($stillSaved[$b->meta['saved_first_as'] ?? 0]))<a href="{{ route('setup.snapshots.show', $b->meta['saved_first_as']) }}" class="btn btn-sm btn-soft-secondary" title="Put back what was there before this restore"><i class="ri-arrow-go-back-line me-1"></i>Undo this restore</a>@endif</td></tr>
                        @endforeach
                        </tbody></table></div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
