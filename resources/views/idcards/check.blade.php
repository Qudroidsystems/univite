<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Identity card check · {{ \App\Support\Institution::get('name') }}</title>
<link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:520px">
    <div class="text-center mb-3">
        @if (\App\Support\Institution::image('logo'))<img src="{{ \App\Support\Institution::image('logo') }}" alt="" style="height:56px">@endif
        <h5 class="mt-2 mb-0">{{ \App\Support\Institution::get('name') }}</h5>
        <div class="text-muted small">Identity card check</div>
    </div>
    <div class="card border-{{ $check->valid ? 'success' : 'danger' }}">
        <div class="card-header text-white bg-{{ $check->valid ? 'success' : 'danger' }} fw-semibold">{{ $check->valid ? '✓ VALID' : '✗ NOT VALID' }}</div>
        <div class="card-body">
            <p class="mb-3">{{ $check->message }}</p>
            @if ($c = $check->card)
                <div class="d-flex gap-3">
                    @if ($c->photoUrl())<img src="{{ $c->photoUrl() }}" alt="" style="width:90px;height:112px;object-fit:cover" class="rounded border">@endif
                    <div>
                        <div class="fw-bold">{{ $c->snap('name') }}</div>
                        <div>{{ $c->snap('type') }} · {{ $c->snap('number') }}</div>
                        @if ($c->snap('line1'))<div class="small">{{ $c->snap('line1') }}</div>@endif
                        @if ($c->snap('line2'))<div class="small">{{ $c->snap('line2') }}</div>@endif
                        <div class="small text-muted mt-1">Card {{ $c->serial }}</div>
                    </div>
                </div>
            @endif
        </div>
    </div>
    <p class="text-muted small text-center mt-3">Checked {{ now()->format('j F Y, g:ia') }}. Compare the photograph with the person holding the card.</p>
</div>
</body></html>
