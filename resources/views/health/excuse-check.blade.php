<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Excuse note check · {{ \App\Support\Institution::get('name') }}</title>
<link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:520px">
    <div class="text-center mb-3">
        @if (\App\Support\Institution::image('logo'))<img src="{{ \App\Support\Institution::image('logo') }}" alt="" style="height:56px">@endif
        <h5 class="mt-2 mb-0">{{ \App\Support\Institution::get('name') }}</h5>
        <div class="text-muted small">Health Centre excuse note check</div>
    </div>
    @if ($visit)
        <div class="card border-success"><div class="card-header bg-success text-white fw-semibold">✓ GENUINE</div><div class="card-body">
            <p class="mb-1"><strong>{{ $visit->user->student?->full_name ?: $visit->user->name }}</strong>{{ $visit->user->student?->matric_number ? ' (' . $visit->user->student->matric_number . ')' : '' }}</p>
            <p class="mb-0">Excused from {{ $visit->excused_from->format('j F Y') }} to {{ $visit->excused_to->format('j F Y') }}.</p>
        </div></div>
    @else
        <div class="card border-danger"><div class="card-header bg-danger text-white fw-semibold">✗ NOT FOUND</div><div class="card-body">This is not an excuse note issued by the Health Centre.</div></div>
    @endif
</div>
</body></html>
