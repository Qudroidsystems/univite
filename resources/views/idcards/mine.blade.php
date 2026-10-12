@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My Identity Card', 'icon' => 'ri-id-card-line'])
    @include('academics.partials.alerts')
    @if ($card)
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                <div><span class="badge {{ $card->stateBadge() }}">{{ $card->stateLabel() }}</span> <span class="fw-semibold ms-1">{{ $card->serial }}</span>
                    <div class="fs-13 text-muted mt-1">Issued {{ $card->issued_at->format('j F Y') }} · valid until {{ $card->expires_on?->format('j F Y') }}{{ $card->collected_at ? '' : ' · ready for collection once printed' }}</div></div>
            </div>
            @include('idcards._card', ['card' => $card, 'settings' => $settings])
            <p class="fs-12 text-muted mb-0">This is a preview. Only the card printed and handed to you by the institution is valid.</p>
        </div></div>
    @elseif (! $pending)
        <div class="alert alert-info">You do not have an identity card yet.@if ($problem) It cannot be issued yet: {{ $problem }}.@endif</div>
    @endif

    @if ($pending)
        <div class="card"><div class="card-body">
            <h6>Your request</h6>
            <p class="mb-1">{{ \App\Models\IdCard::REASONS[$pending->reason] }}, asked on {{ $pending->requested_at->format('j F Y') }}.</p>
            @if ($pending->invoice)
                <p class="mb-1">Replacement fee: ₦{{ number_format((float) $pending->invoice->total, 2) }} — <span class="badge {{ \App\Models\Invoice::BADGES[$pending->invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$pending->invoice->status] }}</span>
                    @if ($pending->invoice->status !== 'paid')<a href="{{ route('my.fees.show', $pending->invoice) }}" class="ms-2">Pay now</a>@endif</p>
            @endif
            <p class="text-muted fs-13">You will be told when the card is ready to collect.</p>
            <form method="POST" action="{{ route('my.id-card.withdraw') }}" onsubmit="return confirm('Withdraw this request?')">@csrf<button class="btn btn-sm btn-light">Withdraw request</button></form>
        </div></div>
    @elseif (! $problem)
        <div class="card"><div class="card-body">
            <h6>{{ $card ? 'Ask for a new card' : 'Ask for a card' }}</h6>
            <form method="POST" action="{{ route('my.id-card.request') }}" class="row g-2">@csrf
                <div class="col-md-5"><select name="reason" class="form-select">@foreach (\App\Models\IdCard::REASONS as $k => $l)@if (($card && $k !== 'first') || (! $card && $k === 'first'))<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select></div>
                <div class="col-md-7"><input name="note" class="form-control" placeholder="Anything we should know (optional)"></div>
                @if ($card && (float) $settings->get('replacement_fee') > 0 && auth()->user()->student)
                    <div class="col-12 fs-13 text-muted">A replacement for a lost or damaged card costs ₦{{ number_format((float) $settings->get('replacement_fee'), 2) }}, payable under My Fees.</div>
                @endif
                <div class="col-12"><button class="btn btn-primary">Send request</button></div>
            </form>
        </div></div>
    @endif

    @if ($history->isNotEmpty())
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Earlier cards</h6></div><ul class="list-group list-group-flush">
            @foreach ($history as $h)<li class="list-group-item d-flex justify-content-between"><span>{{ $h->serial }} · issued {{ $h->issued_at?->format('j M Y') }}</span><span class="badge {{ $h->stateBadge() }}">{{ $h->stateLabel() }}</span></li>@endforeach
        </ul></div>
    @endif
</div></div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>document.querySelectorAll('.idc-qr').forEach(function (el) { if (window.QRCode) { new QRCode(el, { text: el.dataset.url, width: 160, height: 160 }); } });</script>
@endsection
