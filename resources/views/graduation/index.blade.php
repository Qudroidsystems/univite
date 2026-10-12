@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Graduation lists</h4></div>
            @include('academics.partials.alerts')

            @can('Generate graduation lists')
                <form method="POST" action="{{ route('graduation.run') }}" class="card">
                    @csrf
                    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                        <div><label class="form-label mb-1">Programme</label><select name="programme_id" data-lookup="programmes" class="form-select" required><option value="">Select…</option>@foreach ($programmes as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
                        <div><label class="form-label mb-1">Session</label><select name="academic_session_id" data-lookup="sessions" class="form-select">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected($current == $id)>{{ $n }}</option>@endforeach</select></div>
                        <button class="btn btn-primary" type="submit"><i class="ri-play-line me-1"></i>Check final-year students</button>
                    </div>
                    <div class="card-footer text-muted fs-13">Every enrolled student of the programme in its final level is checked: all registered results published, no failed course outstanding, compulsory courses taken and enough units passed (see Result Settings and the programme's "units needed to graduate"). Running it again refreshes the list.</div>
                </form>
            @endcan

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Programme</th><th>Session</th><th>Checked</th><th>Eligible</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($lists as $l)
                            <tr>
                                <td class="fw-semibold">{{ $l->programme->name }}</td>
                                <td>{{ $l->session->name }}</td>
                                <td>{{ $l->entries_count }}<div class="text-muted fs-12">{{ $l->checked_at?->format('j M Y, H:i') }}</div></td>
                                <td>{{ $l->eligible_count }}</td>
                                <td>@if ($l->isDraft())<span class="badge bg-warning-subtle text-warning">Draft</span>@else<span class="badge bg-success-subtle text-success">Approved</span>@endif</td>
                                <td class="text-end"><a href="{{ route('graduation.show', $l->id) }}" class="btn btn-sm btn-soft-primary">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-5">No graduation lists yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($lists->hasPages())<div class="card-footer">{{ $lists->links() }}</div>@endif
            </div>
        </div>
    </div>
</div>
@endsection
