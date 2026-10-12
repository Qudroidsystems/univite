<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Identity cards</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
    body { margin: 0; background: #eef0f3; font-family: Arial, Helvetica, sans-serif; }
    .bar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ddd; padding: 10px 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; z-index: 2; }
    .bar a, .bar button { font: inherit; padding: 6px 12px; border-radius: 4px; border: 1px solid #ccc; background: #f8f9fa; color: #222; text-decoration: none; cursor: pointer; }
    .bar .primary { background: #405189; color: #fff; border-color: #405189; }
    .sheet { padding: 10mm; }
    @page { size: A4; margin: 8mm; }
    @media print { .bar { display: none; } body { background: #fff; } .sheet { padding: 0; } }
    body.printer .idc-pair { flex-direction: column; gap: 0; margin: 0; }
    body.printer .idc { page-break-after: always; border: 0; border-radius: 0; }
</style>
@if (request('layout') === 'printer')<style>@page { size: 85.6mm 54mm; margin: 0; }</style>@endif
</head>
<body class="{{ request('layout') === 'printer' ? 'printer' : '' }}">
<div class="bar">
    <strong>{{ $cards->count() }} card(s)</strong>
    <button class="primary" onclick="window.print()">Print</button>
    <a href="{{ request()->fullUrlWithQuery(['layout' => request('layout') === 'printer' ? null : 'printer']) }}">{{ request('layout') === 'printer' ? 'Use A4 sheets' : 'Use a card printer (one card per page)' }}</a>
    @if ($cards->isNotEmpty())
        <form method="POST" action="{{ route('id-cards.printed') }}" style="margin:0">@csrf
            @foreach ($cards as $c)<input type="hidden" name="ids[]" value="{{ $c->id }}">@endforeach
            <button>Mark these as printed</button>
        </form>
    @endif
    <a href="{{ route('id-cards.index') }}">Back to the register</a>
    @if ($cards->count() === 500)<span>Only the first 500 are shown. Print these, mark them printed, then open this page again.</span>@endif
</div>
<div class="sheet">
    @forelse ($cards as $card)
        @include('idcards._card', ['card' => $card, 'settings' => $settings])
    @empty
        <p>No cards to print.</p>
    @endforelse
</div>
<script>document.querySelectorAll('.idc-qr').forEach(function (el) { if (window.QRCode) { new QRCode(el, { text: el.dataset.url, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M }); } });</script>
</body></html>
