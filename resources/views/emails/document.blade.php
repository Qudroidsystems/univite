@php
    use App\Support\Institution;
    $logo = Institution::image('logo');
    $color = Institution::get('primary_color', '#405189');
    $sign = $doc['signatory'] ?? null;
    $signName = $sign ? Institution::get($sign . '_name') : null;
    $signTitle = ['registrar' => 'Registrar', 'bursar' => 'Bursar'][$sign] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $doc['title'] }}</title></head>
<body style="margin:0;padding:0;background:#f3f4f7;font-family:Arial,Helvetica,sans-serif;color:#212529;font-size:14px;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f7;padding:24px 8px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:6px;border-top:4px solid {{ $color }}">
    <tr><td style="padding:24px 28px 8px;text-align:center">
        @if ($logo)<img src="{{ $logo }}" alt="" height="64" style="height:64px;max-width:200px;margin-bottom:8px">@endif
        <div style="font-size:18px;font-weight:bold;text-transform:uppercase">{{ Institution::get('name') }}</div>
        @if (Institution::addressLine())<div style="font-size:12px;color:#6c757d">{{ Institution::addressLine() }}</div>@endif
        @if (Institution::get('email') || Institution::get('phone'))<div style="font-size:12px;color:#6c757d">{{ collect([Institution::get('email'), Institution::get('phone'), Institution::get('website')])->filter()->implode(' · ') }}</div>@endif
        <div style="margin-top:12px;font-size:15px;font-weight:bold;color:{{ $color }}">{{ $doc['title'] }}</div>
    </td></tr>
    @if (! empty($doc['banner']))
        <tr><td style="padding:8px 28px"><div style="padding:10px 12px;border-radius:4px;background:{{ ($doc['banner'][1] ?? 'info') === 'danger' ? '#fde8e8' : (($doc['banner'][1] ?? '') === 'success' ? '#e6f6ee' : '#fff4de') }};font-weight:bold">{{ $doc['banner'][0] }}</div></td></tr>
    @endif
    <tr><td style="padding:8px 28px">
        @if (! empty($doc['greeting']))<p style="margin:8px 0">{{ $doc['greeting'] }}</p>@endif
        @foreach ((array) ($doc['intro'] ?? []) as $p)<p style="margin:8px 0">{{ $p }}</p>@endforeach
    </td></tr>
    @if (! empty($doc['details']))
        <tr><td style="padding:8px 28px">
            <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px">
                @foreach ($doc['details'] as $label => $value)
                    <tr><td style="width:38%;border-bottom:1px solid #e9ebec;color:#6c757d">{{ $label }}</td><td style="border-bottom:1px solid #e9ebec;font-weight:bold">{{ $value }}</td></tr>
                @endforeach
            </table>
        </td></tr>
    @endif
    @if (! empty($doc['table']))
        <tr><td style="padding:12px 28px">
            <table role="presentation" width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px">
                <tr>@foreach ($doc['table']['head'] as $i => $h)<th style="background:#f3f6f9;text-align:{{ in_array($i, $doc['table']['right'] ?? [], true) ? 'right' : 'left' }};border-bottom:1px solid #e9ebec">{{ $h }}</th>@endforeach</tr>
                @foreach ($doc['table']['rows'] as $row)
                    <tr>@foreach ($row as $i => $cell)<td style="border-bottom:1px solid #e9ebec;text-align:{{ in_array($i, $doc['table']['right'] ?? [], true) ? 'right' : 'left' }}">{{ $cell }}</td>@endforeach</tr>
                @endforeach
                @foreach ($doc['table']['foot'] ?? [] as $row)
                    <tr>@foreach ($row as $i => $cell)<th style="text-align:{{ in_array($i, $doc['table']['right'] ?? [], true) ? 'right' : 'left' }}">{{ $cell }}</th>@endforeach</tr>
                @endforeach
            </table>
        </td></tr>
    @endif
    @if ($sign)
        <tr><td style="padding:16px 28px 0">
            @if ($sign === 'registrar' && Institution::image('registrar_signature'))<img src="{{ Institution::image('registrar_signature') }}" alt="" height="48" style="height:48px"><br>@endif
            @if ($signName)<strong>{{ $signName }}</strong><br>@endif
            <span style="color:#6c757d;font-size:12px">{{ $signTitle }}</span>
        </td></tr>
    @endif
    @foreach ((array) ($doc['outro'] ?? []) as $p)
        <tr><td style="padding:8px 28px 0"><p style="margin:8px 0;color:#495057">{{ $p }}</p></td></tr>
    @endforeach
    @if (! empty($doc['link']))
        <tr><td style="padding:16px 28px"><a href="{{ $doc['link'][0] }}" style="display:inline-block;background:{{ $color }};color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;font-weight:bold">{{ $doc['link'][1] }}</a></td></tr>
    @endif
    <tr><td style="padding:16px 28px 24px;font-size:11px;color:#878a99;border-top:1px solid #e9ebec">
        {{ $doc['reference'] ?? '' }}{{ ! empty($doc['reference']) ? ' · ' : '' }}Generated {{ now()->format('j M Y, g:i A') }} by the {{ Institution::get('short_name') ?: Institution::get('name') }} portal.
        This email was sent automatically; keep it as your record.
        @if (Institution::get('support_email')) Questions: {{ Institution::get('support_email') }}.@endif
    </td></tr>
</table>
</td></tr></table>
</body></html>
