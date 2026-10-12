{{-- One identity card, front and back. Pass $card (App\Models\IdCard) and $settings (IdCardSettings). Sizes are those of a standard ID-1 card (85.6 × 54 mm). --}}
@php
    $I = \App\Support\Institution::class;
    $colour = $settings->colour();
    $logo = $I::image('logo');
    $sig = $I::image('registrar_signature');
@endphp
<div class="idc-pair">
    <div class="idc idc-front">
        <div class="idc-band" style="background: {{ $colour }}">
            @if ($logo)<img src="{{ $logo }}" alt="" class="idc-logo">@endif
            <div class="idc-inst"><div class="idc-inst-name">{{ $I::get('name') }}</div>@if ($I::get('motto'))<div class="idc-motto">{{ $I::get('motto') }}</div>@endif</div>
        </div>
        <div class="idc-body">
            <div class="idc-photo">@if ($card->photoUrl())<img src="{{ $card->photoUrl() }}" alt="">@endif</div>
            <div class="idc-details">
                <div class="idc-type" style="color: {{ $colour }}">{{ strtoupper($card->snap('type')) }} IDENTITY CARD</div>
                <div class="idc-name">{{ $card->snap('name') }}</div>
                <div class="idc-number">{{ $card->snap('number') }}</div>
                @if ($card->snap('line1'))<div class="idc-line">{{ $card->snap('line1') }}</div>@endif
                @if ($card->snap('line2'))<div class="idc-line">{{ $card->snap('line2') }}</div>@endif
                @if ($card->snap('line3'))<div class="idc-line">{{ $card->snap('line3') }}</div>@endif
            </div>
        </div>
        <div class="idc-foot" style="border-color: {{ $colour }}"><span>Issued {{ $card->issued_at?->format('M Y') }}</span><span>Valid until {{ $card->expires_on?->format('M Y') }}</span></div>
    </div>
    <div class="idc idc-back">
        <div class="idc-back-top">
            <div class="idc-qr" data-url="{{ route('id-card.check', $card->token) }}"></div>
            <div class="idc-back-text">
                <div class="idc-serial">No. {{ $card->serial }}</div>
                @if ($card->snap('blood'))<div class="idc-line" style="font-weight:700">{{ $card->snap('blood') }}</div>@endif
                <div class="idc-small">{{ $settings->get('back_text') }}</div>
            </div>
        </div>
        <div class="idc-sign">
            <div>@if ($sig)<img src="{{ $sig }}" alt="">@endif<div class="idc-sign-line">{{ $settings->signatoryName() }}</div><div class="idc-small">{{ $settings->get('signatory_title') }}</div></div>
            <div class="idc-small" style="text-align:right">Scan to check this card<br>{{ \Illuminate\Support\Str::after(config('app.url'), '://') }}</div>
        </div>
    </div>
</div>
@once
<style>
    .idc-pair { display: flex; gap: 6mm; flex-wrap: wrap; margin-bottom: 6mm; break-inside: avoid; page-break-inside: avoid; }
    .idc { width: 85.6mm; height: 54mm; border-radius: 3mm; overflow: hidden; background: #fff; border: .2mm solid #cfd4da; position: relative; font-family: Arial, Helvetica, sans-serif; color: #222; box-sizing: border-box; }
    .idc-band { height: 13mm; display: flex; align-items: center; gap: 2mm; padding: 0 3mm; color: #fff; }
    .idc-logo { height: 10mm; width: auto; background: #fff; border-radius: 1.5mm; padding: .5mm; }
    .idc-inst-name { font-size: 2.9mm; font-weight: 700; line-height: 1.15; text-transform: uppercase; }
    .idc-motto { font-size: 2mm; opacity: .9; font-style: italic; }
    .idc-body { display: flex; gap: 3mm; padding: 2.5mm 3mm 0; }
    .idc-photo { width: 21mm; height: 26mm; border: .3mm solid #ccc; border-radius: 1mm; overflow: hidden; flex-shrink: 0; background: #f3f3f3; }
    .idc-photo img { width: 100%; height: 100%; object-fit: cover; }
    .idc-details { min-width: 0; }
    .idc-type { font-size: 2.2mm; font-weight: 700; letter-spacing: .2mm; }
    .idc-name { font-size: 3.4mm; font-weight: 700; line-height: 1.15; margin-top: .8mm; }
    .idc-number { font-size: 3mm; font-weight: 700; margin: .6mm 0; }
    .idc-line { font-size: 2.3mm; line-height: 1.25; }
    .idc-foot { position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: space-between; padding: 1mm 3mm; font-size: 2.2mm; border-top: .6mm solid; background: #fafafa; }
    .idc-back { padding: 3mm; display: flex; flex-direction: column; justify-content: space-between; }
    .idc-back-top { display: flex; gap: 3mm; }
    .idc-qr { width: 22mm; height: 22mm; flex-shrink: 0; }
    .idc-qr img, .idc-qr canvas { width: 22mm !important; height: 22mm !important; }
    .idc-serial { font-size: 2.6mm; font-weight: 700; margin-bottom: .8mm; }
    .idc-small { font-size: 2mm; line-height: 1.3; }
    .idc-sign { display: flex; justify-content: space-between; align-items: flex-end; }
    .idc-sign img { height: 7mm; width: auto; display: block; }
    .idc-sign-line { border-top: .2mm solid #555; font-size: 2.2mm; padding-top: .3mm; min-width: 28mm; }
    @media print { .idc { border-color: #ddd; } }
</style>
@endonce
