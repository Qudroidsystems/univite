@extends('layouts.master')

@section('content')
@php
    $field = fn ($k, $label, $help = null, $type = 'text') => compact('k', 'label', 'help', 'type');
    $groups = [
        'twilio' => ['Twilio', [$field('twilio_sid', 'Account SID'), $field('twilio_token', 'Auth token', null, 'password'), $field('twilio_from', 'SMS sender number', 'In international format, e.g. +15005550006.'), $field('twilio_whatsapp_from', 'WhatsApp sender number', 'The number approved for WhatsApp on Twilio.')]],
        'termii' => ['Termii', [$field('termii_key', 'API key', null, 'password'), $field('termii_sender', 'Sender ID', 'Up to 11 characters, registered with Termii.')]],
        'webhook' => ['Other provider (webhook)', [$field('webhook_url', 'Webhook URL', 'The portal posts JSON {channel, to, body} to this https address.'), $field('webhook_token', 'Bearer token', 'Sent as Authorization: Bearer …', 'password')]],
    ];
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Messaging settings', 'icon' => 'ri-message-3-line', 'subtitle' => 'Text messages and WhatsApp. Emails use the mail settings; portal notices always go out.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('messaging.settings.update') }}" autocomplete="off">@csrf @method('PUT')
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Providers</h6></div><div class="card-body row g-3">
            <div class="col-md-4"><label class="form-label">Text messages (SMS)</label><select name="sms_provider" class="form-select">@foreach ($providers as $k => $l)<option value="{{ $k }}" @selected(old('sms_provider', $values['sms_provider'] ?? 'log') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">WhatsApp</label><select name="whatsapp_provider" class="form-select">@foreach ($providers as $k => $l)<option value="{{ $k }}" @selected(old('whatsapp_provider', $values['whatsapp_provider'] ?? 'log') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-4"><label class="form-label">Country code for local numbers</label><input name="country_code" value="{{ old('country_code', $values['country_code'] ?? '234') }}" class="form-control"><div class="form-text">08031234567 becomes 2348031234567.</div></div>
            <div class="col-12 text-muted fs-13">Until a provider is chosen, messages are written to the message log and the application log only, so nothing is sent by accident.</div>
        </div></div>

        @foreach ($groups as $g => [$title, $fields])
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $title }}</h6></div><div class="card-body row g-3">
                @foreach ($fields as $f)
                    <div class="col-md-6"><label class="form-label">{{ $f['label'] }}</label>
                        @if ($f['type'] === 'password')
                            <input type="password" name="{{ $f['k'] }}" class="form-control" autocomplete="new-password" placeholder="{{ $secretSet[$f['k']] ? 'Saved. Leave empty to keep it.' : '' }}">
                        @else
                            <input name="{{ $f['k'] }}" value="{{ old($f['k'], $values[$f['k']]) }}" class="form-control">
                        @endif
                        @if ($f['help'])<div class="form-text">{{ $f['help'] }}</div>@endif</div>
                @endforeach
            </div></div>
        @endforeach

        <div class="card"><div class="card-header"><h6 class="card-title mb-1">What goes out by text and WhatsApp</h6><div class="text-muted fs-12">Every notice also appears under the bell in the portal. Each person can turn text or WhatsApp off for themselves.</div></div>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Topic</th><th class="text-center">SMS</th><th class="text-center">WhatsApp</th></tr></thead>
                <tbody>@foreach ($topics as $t => $row)<tr><td>{{ $row['label'] }}</td>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="topics[{{ $t }}][sms]" value="1" @checked($row['sms'])></td>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="topics[{{ $t }}][whatsapp]" value="1" @checked($row['whatsapp'])></td></tr>@endforeach</tbody>
            </table></div></div>
        <button class="btn btn-primary mb-4">Save messaging settings</button>
    </form>

    <div class="card"><div class="card-header"><h6 class="card-title mb-0">Send a test message</h6></div><div class="card-body">
        <form method="POST" action="{{ route('messaging.test') }}" class="d-flex flex-wrap gap-2">@csrf
            <select name="channel" class="form-select" style="width:auto"><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select>
            <input name="to" class="form-control" style="width:220px" placeholder="Phone number" value="{{ auth()->user()->phone_number }}" required>
            <button class="btn btn-soft-primary">Send test</button>
        </form></div></div>
</div></div></div>
@endsection
