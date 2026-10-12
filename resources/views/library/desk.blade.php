@extends('layouts.master')

@section('content')
@php $u = auth()->user(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Library desk', 'icon' => 'ri-book-open-line', 'subtitle' => 'Scan or type the borrower\'s number and the book\'s accession number.'])
    @include('academics.partials.alerts')
    <div class="row">
        <div class="col-lg-6"><div class="card"><div class="card-header"><h6 class="card-title mb-0">Lend</h6></div><div class="card-body">
            <form method="POST" action="{{ route('library.issue') }}" class="row g-2">@csrf
                <div class="col-sm-6"><input name="borrower" value="{{ old('borrower', request('borrower')) }}" class="form-control" placeholder="Matric or staff number" required autofocus></div>
                <div class="col-sm-4"><input name="accession" class="form-control" placeholder="Accession no." required></div>
                <div class="col-sm-2"><button class="btn btn-primary w-100">Lend</button></div>
            </form>
        </div></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-header"><h6 class="card-title mb-0">Return</h6></div><div class="card-body">
            <form method="POST" action="{{ route('library.return') }}" class="row g-2">@csrf
                <div class="col-sm-9"><input name="accession" class="form-control" placeholder="Accession no." required></div>
                <div class="col-sm-3"><button class="btn btn-success w-100">Check in</button></div>
            </form>
        </div></div></div>
    </div>

    <div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="card-title mb-0">{{ $borrower ? $label : 'Look up a borrower' }}</h6>
        <form method="GET" class="d-flex gap-2"><input name="borrower" value="{{ request('borrower') }}" class="form-control form-control-sm" placeholder="Matric or staff number"><button class="btn btn-sm btn-light">Look up</button></form>
    </div>
    @if (request('borrower') && ! $borrower)
        <div class="card-body text-muted">Nobody found with that number.</div>
    @elseif ($borrower)
        <div class="card-body pb-0">
            <span class="me-3">{{ $standing->onLoan }} of {{ $standing->limit }} books out</span>
            @if ($standing->overdue)<span class="badge bg-danger me-2">{{ $standing->overdue }} overdue</span>@endif
            @if ($standing->finesOwed > 0)<span class="badge bg-warning-subtle text-warning me-2">Owes ₦{{ number_format($standing->finesOwed, 2) }}</span>@endif
            @if ($standing->canBorrow())<span class="badge bg-success-subtle text-success">May borrow</span>@else<span class="badge bg-danger-subtle text-danger">Cannot borrow: {{ $standing->blockedBecause }}</span>@endif
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0 mt-2">
            <thead class="table-light"><tr><th>Book</th><th>Lent</th><th>Due</th><th>Fine</th><th></th></tr></thead><tbody>
            @forelse ($loans as $l)
                <tr>
                    <td>{{ $l->copy->item->title }}<div class="fs-12 text-muted">{{ $l->copy->accession_number }}</div></td>
                    <td class="fs-13">{{ $l->issued_at->format('j M Y') }}</td>
                    <td class="fs-13 {{ $l->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $l->returned_at ? 'Returned ' . $l->returned_at->format('j M') : $l->due_at->format('j M Y') }}@if ($l->renewals)<div class="fs-12 text-muted">Renewed {{ $l->renewals }}×</div>@endif</td>
                    <td>@if ((float) $l->fine > 0)<span class="badge {{ \App\Models\LibraryLoan::FINE_BADGES[$l->fine_status] }}">₦{{ number_format((float) $l->fine, 2) }} · {{ \App\Models\LibraryLoan::FINE_STATUSES[$l->fine_status] }}</span>@endif</td>
                    <td class="text-end text-nowrap">
                        @if ($l->isOpen())
                            <form method="POST" action="{{ route('library.loans.renew', $l) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light">Renew</button></form>
                            <form method="POST" action="{{ route('library.loans.lost', $l) }}" class="d-inline" onsubmit="var c = prompt('The borrower lost this book. Amount to charge for it (₦), on top of any overdue fine:', '{{ (float) $l->copy->price }}'); if (c === null) return false; this.charge.value = c;">@csrf<input type="hidden" name="charge"><button class="btn btn-sm btn-outline-danger">Lost</button></form>
                        @endif
                        @if ($l->fine_status === 'owed' && $u->can('Record library fine payments'))
                            <form method="POST" action="{{ route('library.fines.paid', $l) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Paid</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No books out and nothing owed.</td></tr>
            @endforelse
        </tbody></table></div>
    @endif
    </div>

    <div class="card"><div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Today at the desk</h6><a href="{{ route('library.loans') }}" class="fs-13">All loans</a></div>
        <ul class="list-group list-group-flush">
            @forelse ($today as $l)
                <li class="list-group-item fs-13 d-flex justify-content-between"><span>{{ $l->copy->item->title }} <span class="text-muted">({{ $l->copy->accession_number }})</span> · {{ $l->user->name }}</span>
                    <span class="text-muted">{{ $l->returned_at ? 'Returned ' . $l->returned_at->format('g:ia') : 'Lent ' . $l->issued_at->format('g:ia') }}</span></li>
            @empty
                <li class="list-group-item text-muted fs-13">Nothing yet today.</li>
            @endforelse
        </ul>
    </div>
</div></div></div>
@endsection
