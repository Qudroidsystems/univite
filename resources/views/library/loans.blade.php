@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Loans', 'icon' => 'ri-book-read-line'])
    @include('academics.partials.alerts')
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
            <ul class="nav nav-pills">
                @foreach (['out' => 'On loan', 'overdue' => 'Overdue', 'returned' => 'Returned', 'all' => 'All'] as $k => $l)
                    <li class="nav-item"><a class="nav-link py-1 {{ $show === $k ? 'active' : '' }}" href="{{ route('library.loans', ['show' => $k]) }}">{{ $l }}@if ($k === 'overdue' && $overdue) <span class="badge bg-danger">{{ $overdue }}</span>@endif</a></li>
                @endforeach
            </ul>
            <form method="GET" class="d-flex gap-2"><input type="hidden" name="show" value="{{ $show }}"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Borrower, matric no., title or accession no."><button class="btn btn-sm btn-light">Search</button></form>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Book</th><th>Borrower</th><th>Lent</th><th>Due</th><th>Returned</th><th>Fine</th><th style="width:1%" class="text-nowrap">Last changed by</th></tr></thead><tbody>
            @forelse ($loans as $l)
                <tr>
                    <td>{{ $l->copy->item->title }}<div class="fs-12 text-muted">{{ $l->copy->accession_number }}</div></td>
                    <td>{{ $l->user->student?->full_name ?: $l->user->name }}<div class="fs-12 text-muted">{{ $l->user->student?->matric_number ?? $l->user->staffProfile?->staff_number }}</div></td>
                    <td class="fs-13">{{ $l->issued_at->format('j M Y') }}</td>
                    <td class="fs-13 {{ $l->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $l->due_at->format('j M Y') }}</td>
                    <td class="fs-13">{{ $l->lost ? 'Lost' : ($l->returned_at?->format('j M Y') ?? '—') }}</td>
                    <td>@if ((float) $l->fine > 0)<span class="badge {{ \App\Models\LibraryLoan::FINE_BADGES[$l->fine_status] }}">₦{{ number_format((float) $l->fine, 2) }}</span>@endif</td>
                    <td><x-last-change :record="$l" :among="$loans" /></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">No loans here.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($loans->hasPages())<div class="card-footer">{{ $loans->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
