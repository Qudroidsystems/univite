@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My Library', 'icon' => 'ri-book-2-line'])
    @include('academics.partials.alerts')
    @unless ($standing->canBorrow())<div class="alert alert-warning">You cannot borrow at the moment: {{ $standing->blockedBecause }}.</div>@endunless

    <div class="card"><div class="card-body">
        <form method="GET" class="d-flex gap-2"><input name="q" value="{{ $q }}" class="form-control" placeholder="Search the catalogue by title, author, subject or ISBN"><button class="btn btn-primary">Search</button></form>
        @if ($q !== '')
            <div class="table-responsive mt-3"><table class="table align-middle mb-0"><tbody>
                @forelse ($results as $i)
                    <tr>
                        <td><div class="fw-semibold">{{ $i->title }}</div><div class="fs-12 text-muted">{{ $i->authors }}{{ $i->year ? ' · ' . $i->year : '' }}{{ $i->call_number ? ' · ' . $i->call_number : '' }}</div></td>
                        <td class="fs-13">
                            @if ($i->url)<a href="{{ $i->url }}" target="_blank" rel="noopener">Open online</a>
                            @elseif ($i->is_reference) In the library only
                            @elseif ($i->available_count) {{ $i->available_count }} on the shelf
                            @elseif ($i->copies_count) All copies on loan
                            @else Not available @endif
                        </td>
                        <td class="text-end">@if (! $i->available_count && $i->copies_count && ! $i->is_reference && ! $i->url)<form method="POST" action="{{ route('my.library.hold', $i) }}">@csrf<button class="btn btn-sm btn-light">Join waiting list</button></form>@endif</td>
                    </tr>
                @empty
                    <tr><td class="text-muted">Nothing found.</td></tr>
                @endforelse
            </tbody></table></div>
        @endif
    </div></div>

    <div class="row">
        <div class="col-lg-7"><div class="card"><div class="card-header"><h6 class="card-title mb-0">Books I have ({{ $standing->onLoan }} of {{ $standing->limit }})</h6></div>
            <ul class="list-group list-group-flush">
                @forelse ($loans as $l)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div><div class="fw-semibold">{{ $l->copy->item->title }}</div><div class="fs-12 {{ $l->isOverdue() ? 'text-danger' : 'text-muted' }}">{{ $l->isOverdue() ? 'Overdue since' : 'Due' }} {{ $l->due_at->format('D j M Y') }}</div></div>
                        @if ($settings->on('self_renew') && ! $l->isOverdue() && $l->renewals < $settings->int('max_renewals'))<form method="POST" action="{{ route('my.library.renew', $l) }}">@csrf<button class="btn btn-sm btn-light">Renew</button></form>@endif
                    </li>
                @empty
                    <li class="list-group-item text-muted">No books out.</li>
                @endforelse
            </ul></div>
            @if ($history->isNotEmpty())
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Recently returned</h6></div><ul class="list-group list-group-flush">
                    @foreach ($history as $l)<li class="list-group-item fs-13 d-flex justify-content-between"><span>{{ $l->copy->item->title }}</span><span class="text-muted">{{ $l->lost ? 'Lost' : $l->returned_at->format('j M Y') }}</span></li>@endforeach
                </ul></div>
            @endif
        </div>
        <div class="col-lg-5">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Waiting for</h6></div><ul class="list-group list-group-flush">
                @forelse ($holds as $h)
                    <li class="list-group-item d-flex justify-content-between align-items-center"><div>{{ $h->item->title }}@if ($h->status === 'ready')<div class="fs-12 text-success">Ready: collect by {{ $h->expires_at->format('j M Y') }}</div>@endif</div>
                        <form method="POST" action="{{ route('my.library.hold.cancel', $h) }}">@csrf<button class="btn btn-sm btn-link text-danger">Cancel</button></form></li>
                @empty
                    <li class="list-group-item text-muted fs-13">Nothing.</li>
                @endforelse
            </ul></div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Fines</h6></div><ul class="list-group list-group-flush">
                @forelse ($fines as $l)
                    <li class="list-group-item fs-13 d-flex justify-content-between"><span>{{ $l->fine_note ?? $l->copy->item->title }}</span>
                        <span class="text-nowrap ms-2">₦{{ number_format((float) $l->fine, 2) }} <span class="badge {{ \App\Models\LibraryLoan::FINE_BADGES[$l->fine_status] }}">{{ \App\Models\LibraryLoan::FINE_STATUSES[$l->fine_status] }}</span>
                            @if ($l->invoice && $l->fineUnpaid()) <a href="{{ route('my.fees.show', $l->invoice) }}">Pay</a>@endif</span></li>
                @empty
                    <li class="list-group-item text-muted fs-13">None.</li>
                @endforelse
            </ul></div>
        </div>
    </div>
</div></div></div>
@endsection
