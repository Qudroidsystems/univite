@extends('layouts.master')

@php $allFonts = app(\App\Support\Appearance::class)->fontsUrl(...array_keys($fonts)); @endphp
@push('styles')
    @if ($allFonts)<link href="{{ $allFonts }}" rel="stylesheet">@endif
@endpush

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Appearance', 'icon' => 'ri-font-size-2', 'subtitle' => 'The font and text size everyone sees in the portal and the mobile app, until they choose their own under Display & reading.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('appearance.update') }}" class="card" data-no-ajax id="uv-look-form"><div class="card-body">@csrf @method('PUT')
                @include('partials.settings-fields', ['group' => $group, 'values' => $values])
            </div><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div></form>
        </div>
        <div class="col-xl-4">
            <div class="card"><div class="card-body">
                <h6 class="mb-2">Preview</h6>
                <div class="uv-look-preview" id="uv-look-preview">
                    <div class="fw-semibold mb-1" style="font-size:1.1em">Course registration</div>
                    <div class="uv-look-sample">EDU 211 · Curriculum Studies · 2 units<br>Registered 24 of 24 units for the first semester. Your adviser approves it next.</div>
                </div>
                <div class="text-muted fs-12 mt-2">Printed documents (slips, receipts, transcripts) keep their own layout.</div>
            </div></div>
        </div>
    </div>
</div></div></div>

<script>
(function () {
    var stacks = @json(collect($fonts)->map(fn ($f) => $f[2])), box = document.getElementById('uv-look-preview');
    function show() {
        var f = document.getElementById('set-font'), s = document.getElementById('set-size');
        if (f) { box.style.fontFamily = stacks[f.value] || ''; }
        if (s) { box.querySelector('.uv-look-sample').style.fontSize = s.value + 'px'; }
    }
    ['set-font', 'set-size'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.addEventListener('change', show); } });
    show();
})();
</script>
@endsection
