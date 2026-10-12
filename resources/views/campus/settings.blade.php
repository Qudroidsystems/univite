@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Mobile App', 'icon' => 'ri-smartphone-line', 'subtitle' => 'What the UniVite app shows and how it connects. Phones pick up changes the next time the app opens.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if (($values['allow_untrusted_certificate'] ?? '0') === '1')
        <div class="alert alert-warning"><i class="ri-alert-line me-1"></i>The app is allowed to connect even though this website's security certificate cannot be verified. Fix the certificate (in cPanel: SSL/TLS Status, run AutoSSL for this domain) and switch this off.</div>
    @endif
    <form method="POST" action="{{ route('campus.settings.save') }}" class="card" autocomplete="off"><div class="card-body">@csrf @method('PUT')
        @include('partials.settings-fields', ['group' => $group, 'values' => $values])
    </div><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div></form>
</div></div></div>
@endsection
