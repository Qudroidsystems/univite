@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Polls & Elections', 'icon' => 'ri-checkbox-multiple-line', 'subtitle' => 'Polls, surveys and elections open to you.'])
    @include('academics.partials.alerts')
    @forelse ($polls as $p)
        @php [$label, $badge] = $p->phaseBadge(); @endphp
        <a href="{{ route('my.polls.show', $p) }}" class="card text-body text-decoration-none"><div class="card-body d-flex justify-content-between align-items-center gap-3">
            <div><div class="fw-semibold">{{ $p->title }}</div>
                <div class="fs-12 text-muted">{{ \App\Models\Poll::KINDS[$p->kind] }} · closes {{ $p->closes_at->format('j M Y, g:ia') }}</div></div>
            <div class="text-end">
                <span class="badge {{ $badge }}">{{ $label }}</span>
                @if ($p->has_voted)<div><span class="badge bg-success-subtle text-success mt-1"><i class="ri-check-line"></i> You voted</span></div>
                @elseif ($p->isOpen())<div class="mt-1"><span class="btn btn-sm btn-primary">Vote now</span></div>@endif
            </div>
        </div></a>
    @empty
        <div class="card"><div class="card-body text-center text-muted py-5">There is nothing to vote on at the moment.</div></div>
    @endforelse
</div></div></div>
@endsection
