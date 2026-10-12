@extends('layouts.master')

@section('content')
@php $u = auth()->user(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Library fines', 'icon' => 'ri-money-dollar-circle-line', 'subtitle' => 'Students\' fines are billed to My Fees; staff pay at the desk.'])
    @include('academics.partials.alerts')
    <div class="card">
        <div class="card-header"><ul class="nav nav-pills">
            @foreach (['unpaid' => 'Unpaid', 'paid' => 'Paid at the desk', 'invoiced' => 'Billed', 'waived' => 'Waived', 'all' => 'All'] as $k => $l)
                <li class="nav-item"><a class="nav-link py-1 {{ $status === $k ? 'active' : '' }}" href="{{ route('library.fines', ['status' => $k]) }}">{{ $l }}</a></li>
            @endforeach
        </ul></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Borrower</th><th>For</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse ($loans as $l)
                <tr>
                    <td>{{ $l->user->name }}</td>
                    <td class="fs-13">{{ $l->fine_note ?? $l->copy->item->title }}</td>
                    <td class="text-end">₦{{ number_format((float) $l->fine, 2) }}</td>
                    <td><span class="badge {{ \App\Models\LibraryLoan::FINE_BADGES[$l->fine_status] }}">{{ \App\Models\LibraryLoan::FINE_STATUSES[$l->fine_status] }}</span>
                        @if ($l->invoice)<div class="fs-12">{{ $l->invoice->reference }}: {{ \App\Models\Invoice::STATUSES[$l->invoice->status] }}</div>@endif</td>
                    <td class="text-end text-nowrap">
                        @if ($l->fine_status === 'owed' && $u->can('Record library fine payments'))
                            <form method="POST" action="{{ route('library.fines.paid', $l) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success">Paid</button></form>
                        @endif
                        @if ($l->fineUnpaid() && $u->can('Waive library fines'))
                            <form method="POST" action="{{ route('library.fines.waive', $l) }}" class="d-inline" onsubmit="var r = prompt('Why is this fine being waived?'); if (!r) return false; this.reason.value = r;">@csrf<input type="hidden" name="reason"><button class="btn btn-sm btn-light">Waive</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">No fines here.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($loans->hasPages())<div class="card-footer">{{ $loans->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
