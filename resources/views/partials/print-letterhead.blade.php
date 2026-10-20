{{-- Letterhead for stand-alone printed pages and PDFs (no stylesheet needed): logo, name, address, contacts, then $lines. --}}
@php
    $I = \App\Support\Institution::class;
    $color = $I::get('primary_color') ?: '#1f3b73';
    $logo = $I::logoDataUri();
    $contacts = collect([$I::get('phone'), $I::get('email'), $I::get('website')])->filter()->implode(' · ');
@endphp
<table style="width:100%;border-collapse:collapse;border-bottom:2px solid {{ $color }};margin-bottom:8px">
    <tr>
        <td style="width:80px;border:0;vertical-align:middle">@if ($logo)<img src="{{ $logo }}" alt="" style="height:60px">@endif</td>
        <td style="border:0;text-align:center;line-height:1.35">
            <div style="font-size:15px;font-weight:bold;color:{{ $color }};text-transform:uppercase">{{ $I::get('name') }}</div>
            @if ($I::addressLine())<div style="font-size:9.5px">{{ $I::addressLine() }}</div>@endif
            @if ($contacts)<div style="font-size:9px;color:#555">{{ $contacts }}</div>@endif
            @foreach ($lines ?? [] as $i => $line)
                <div style="font-size:{{ $i === 0 ? '12px' : '10px' }};font-weight:{{ $i === 0 ? 'bold' : 'normal' }};{{ $i === 0 ? 'letter-spacing:1px;margin-top:3px' : '' }}">{{ $line }}</div>
            @endforeach
        </td>
        <td style="width:80px;border:0"></td>
    </tr>
</table>
