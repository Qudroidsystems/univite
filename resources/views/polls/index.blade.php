@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Polls & Elections', 'icon' => 'ri-checkbox-multiple-line', 'subtitle' => 'Surveys, opinion polls and student or staff elections. Each person votes once; ballots are secret unless you choose otherwise.'])
    @include('academics.partials.alerts')
    <div class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <form method="GET" class="d-flex gap-2 flex-wrap flex-grow-1">
            <input name="q" value="{{ request('q') }}" class="form-control" style="max-width:260px" placeholder="Search by title">
            <select name="kind" class="form-select" style="max-width:200px"><option value="">Polls and elections</option>@foreach (\App\Models\Poll::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
            <button class="btn btn-light">Filter</button>
        </form>
        @can('Create polls')<a href="{{ route('polls.create') }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>New poll or election</a>@endcan
    </div></div>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Title</th><th>Kind</th><th>Status</th><th>Voting</th><th class="text-end">Voted</th></tr></thead><tbody>
        @forelse ($polls as $p)
            @php [$label, $badge] = $p->phaseBadge(); @endphp
            <tr><td><a href="{{ route('polls.show', $p) }}" class="fw-semibold">{{ $p->title }}</a></td>
                <td>{{ \App\Models\Poll::KINDS[$p->kind] }}</td>
                <td><span class="badge {{ $badge }}">{{ $label }}</span>@if ($p->results_published_at)<span class="badge bg-primary-subtle text-primary ms-1">Results published</span>@endif</td>
                <td class="fs-12">{{ $p->opens_at->format('j M Y, g:ia') }} – {{ $p->closes_at->format('j M Y, g:ia') }}</td>
                <td class="text-end">{{ number_format($p->voters_count) }}</td></tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-5">No polls yet.</td></tr>
        @endforelse
    </tbody></table></div>
    @if ($polls->hasPages())<div class="card-footer">{{ $polls->links() }}</div>@endif</div>
</div></div></div>
@endsection
