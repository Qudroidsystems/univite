@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Marketplace & Lost and Found', 'icon' => 'ri-store-2-line', 'subtitle' => 'Listings students post in the app. Buyers and sellers meet and pay in person.'])
    @include('academics.partials.alerts')
    <ul class="nav nav-pills mb-3">
        @foreach (['pending' => 'Waiting for approval', 'active' => 'Active', 'hidden' => 'Hidden', 'rejected' => 'Not approved', 'sold' => 'Sold', 'closed' => 'Closed', 'all' => 'All'] as $k => $l)
            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ route('campus.market', ['status' => $k]) }}">{{ $l }}@if ($k !== 'all' && ($counts[$k] ?? 0))<span class="badge bg-light text-body ms-1">{{ $counts[$k] }}</span>@endif</a></li>
        @endforeach
    </ul>
    <div class="row g-3">
        @forelse ($listings as $l)
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    @if ($l->photos->first())<img src="{{ $l->photos->first()->url() }}" class="card-img-top" style="height:180px;object-fit:cover" alt="">@endif
                    <div class="card-body">
                        <div class="d-flex justify-content-between"><span class="badge bg-primary-subtle text-primary">{{ \App\Models\MarketListing::KINDS[$l->kind] }}</span><span class="badge bg-light text-body">{{ \App\Models\MarketListing::STATUSES[$l->status] ?? $l->status }}</span></div>
                        <h6 class="mt-2 mb-1">{{ $l->title }}</h6>
                        @if ($l->price !== null)<div class="fw-semibold">₦{{ number_format($l->price, 2) }}</div>@endif
                        <p class="text-muted fs-13 mb-1">{{ \Illuminate\Support\Str::limit($l->description, 160) }}</p>
                        <div class="fs-12 text-muted">{{ $l->user?->name }} · {{ $l->created_at->format('j M Y') }}@if ($l->location) · {{ $l->location }}@endif</div>
                        @if ($l->moderation_note)<div class="fs-12 mt-1">Note: {{ $l->moderation_note }}</div>@endif
                    </div>
                    <form method="POST" action="{{ route('campus.market.moderate', $l->id) }}" class="card-footer d-flex flex-wrap gap-2">@csrf
                        <input name="note" class="form-control form-control-sm" placeholder="Reason (needed to reject or hide)" style="flex:1 1 100%">
                        @if (in_array($l->status, ['pending', 'rejected']))<button name="decision" value="approve" class="btn btn-sm btn-success">Approve</button>@endif
                        @if ($l->status === 'pending')<button name="decision" value="reject" class="btn btn-sm btn-soft-danger">Reject</button>@endif
                        @if (in_array($l->status, ['active', 'sold', 'closed']))<button name="decision" value="hide" class="btn btn-sm btn-soft-warning">Hide</button>@endif
                        @if ($l->status === 'hidden')<button name="decision" value="restore" class="btn btn-sm btn-soft-success">Restore</button>@endif
                    </form>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">Nothing here.</div></div></div>
        @endforelse
    </div>
    {{ $listings->links() }}
</div></div></div>
@endsection
