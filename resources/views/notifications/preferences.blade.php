@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:720px">
    @include('partials.page-title', ['title' => 'Notification settings', 'icon' => 'ri-notification-badge-line', 'back' => [route('notifications.index'), 'Notifications']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="card"><div class="card-body">
        <form method="POST" action="{{ route('notifications.preferences.save') }}">@csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Phone number for text messages</label><input name="phone_number" value="{{ old('phone_number', $u->phone_number) }}" class="form-control" placeholder="0803 123 4567"></div>
                <div class="col-md-6"><label class="form-label">WhatsApp number</label><input name="whatsapp_number" value="{{ old('whatsapp_number', $u->whatsapp_number) }}" class="form-control" placeholder="Same as phone if empty"></div>
                <div class="col-md-6"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="notify_sms" value="1" id="ns" @checked($u->notify_sms)><label class="form-check-label" for="ns">Send me text messages</label></div>
                    <div class="text-muted fs-12 mt-1">{{ $sms->isEmpty() ? 'The institution does not send text messages at the moment.' : 'Used for: ' . $sms->implode('; ') . '.' }}</div></div>
                <div class="col-md-6"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="notify_whatsapp" value="1" id="nw" @checked($u->notify_whatsapp)><label class="form-check-label" for="nw">Send me WhatsApp messages</label></div>
                    <div class="text-muted fs-12 mt-1">{{ $whatsapp->isEmpty() ? 'The institution does not send WhatsApp messages at the moment.' : 'Used for: ' . $whatsapp->implode('; ') . '.' }}</div></div>
            </div>
            <div class="text-muted fs-12 mt-3">Portal notices and important emails (letters, slips) always go out.</div>
            <button class="btn btn-primary mt-3">Save</button>
        </form>
    </div></div>
</div></div></div>
@endsection
