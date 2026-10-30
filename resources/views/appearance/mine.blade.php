@extends('layouts.master')

@php
    $allFonts = app(\App\Support\Appearance::class)->fontsUrl(...array_keys($fonts));
    $val = fn (string $k) => old($k, $saved->toArray()[$k] ?? null);
@endphp
@push('styles')
    @if ($chooseFont && $allFonts)<link href="{{ $allFonts }}" rel="stylesheet">@endif
@endpush

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Display & reading', 'icon' => 'ri-font-size-2', 'subtitle' => 'Make the portal comfortable for you. Your choices also apply in the mobile app and on any computer you sign in on.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('my.appearance.update') }}" data-no-ajax id="uv-my-look">@csrf @method('PUT')
    <div class="row g-3">
        <div class="col-xl-8">
            @if ($chooseSize)
            <div class="card"><div class="card-body">
                <h6 class="mb-1">Text size</h6>
                <div class="text-muted fs-13 mb-3">The school's setting is {{ $defaults->size }}. Larger text makes everything on the page a little bigger, like zooming in.</div>
                <div class="d-flex align-items-center gap-3">
                    <span style="font-size:.75rem">A</span>
                    <input type="range" class="form-range flex-grow-1" min="0" max="{{ count($sizes) - 1 }}" step="1" id="uv-size-range" aria-label="Text size">
                    <span style="font-size:1.4rem">A</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="fw-semibold" id="uv-size-label"></span>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" id="uv-size-default" @checked($val('size') === null)> <span class="form-check-label">Use the school's size</span></label>
                </div>
                <input type="hidden" name="size" id="uv-size" value="{{ $val('size') }}">
            </div></div>
            @endif

            @if ($chooseFont)
            <div class="card"><div class="card-body">
                <h6 class="mb-1">Font</h6>
                <div class="text-muted fs-13 mb-3">The school's font is {{ $fonts[$defaults->font][0] }}.</div>
                <div class="row g-2">
                    <div class="col-sm-6 col-lg-4"><label class="uv-font-choice"><input type="radio" name="font" value="" class="form-check-input me-1" @checked($val('font') === null)> <span class="fw-semibold">The school's font</span><span class="uv-font-sample text-muted">{{ $fonts[$defaults->font][0] }}</span></label></div>
                    @foreach ($fonts as $key => $f)
                        <div class="col-sm-6 col-lg-4"><label class="uv-font-choice" title="{{ $f[3] }}"><input type="radio" name="font" value="{{ $key }}" class="form-check-input me-1" @checked($val('font') === $key)> <span class="fw-semibold">{{ $f[0] }}</span>
                            <span class="uv-font-sample" style="font-family:{{ $f[2] }}">Aa Bb 123 · Il1 O0</span></label></div>
                    @endforeach
                </div>
            </div></div>
            @endif

            <div class="card"><div class="card-body">
                <h6 class="mb-3">Screen</h6>
                <div class="row g-3">
                    @foreach ([['theme', 'Light or dark', \App\Data\Appearance\AppearancePreferences::THEMES], ['menu', 'Menu on wide screens', \App\Data\Appearance\AppearancePreferences::MENUS], ['density', 'Spacing', \App\Data\Appearance\AppearancePreferences::DENSITIES], ['line_spacing', 'Line spacing', \App\Data\Appearance\AppearancePreferences::LINE_SPACINGS]] as [$k, $label, $opts])
                        <div class="col-md-6"><label class="form-label" for="my-{{ $k }}">{{ $label }}</label>
                            <select name="{{ $k }}" id="my-{{ $k }}" class="form-select" data-no-search>
                                <option value="">Default ({{ $opts[$defaults->toArray()[$k]] ?? '' }})</option>
                                @foreach ($opts as $ok => $ol)<option value="{{ $ok }}" @selected($val($k) === $ok)>{{ $ol }}</option>@endforeach
                            </select></div>
                    @endforeach
                    <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="reduce_motion" value="0"><input type="checkbox" class="form-check-input" name="reduce_motion" value="1" id="my-motion" @checked($val('reduce_motion'))><label class="form-check-label" for="my-motion">Less movement (turn off animations)</label></div></div>
                </div>
            </div></div>
        </div>

        <div class="col-xl-4">
            <div class="card" style="position:sticky;top:90px"><div class="card-body">
                <h6 class="mb-2">Preview</h6>
                <div class="uv-look-preview" id="uv-look-preview">
                    <div class="fw-semibold mb-1" style="font-size:1.1em">My results</div>
                    <div class="uv-look-sample">EDU 211 · Curriculum Studies · 2 units · B<br>GPA this semester 3.82 · CGPA 3.64</div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary flex-grow-1">Save</button>
                </div>
                <div class="text-muted fs-12 mt-2">The whole page changes as you choose, so you can see it before saving.</div>
            </div></div>
        </div>
    </div>
    </form>
    <form method="POST" action="{{ route('my.appearance.reset') }}" data-no-ajax class="mt-2">@csrf @method('DELETE')<button class="btn btn-link px-0">Go back to the school's settings</button></form>
</div></div></div>

<script>
(function () {
    var html = document.documentElement, sizes = @json(array_keys($sizes)), labels = @json(array_values($sizes)),
        stacks = @json(collect($fonts)->map(fn ($f) => $f[2])), def = @json($defaults->toArray()), defStack = stacks[def.font];
    var range = document.getElementById('uv-size-range'), hidden = document.getElementById('uv-size'), useDef = document.getElementById('uv-size-default'), label = document.getElementById('uv-size-label');
    function applySize(px) { html.style.setProperty('--uv-scale', (px / 13).toFixed(4)); }
    if (range) {
        var cur = parseInt(hidden.value || def.size, 10), i = Math.max(0, sizes.indexOf(cur));
        range.value = i; label.textContent = labels[i];
        range.addEventListener('input', function () { var px = sizes[range.value]; hidden.value = px; useDef.checked = false; label.textContent = labels[range.value]; applySize(px); });
        useDef.addEventListener('change', function () { if (useDef.checked) { hidden.value = ''; var j = sizes.indexOf(def.size); range.value = j; label.textContent = labels[j]; applySize(def.size); } });
    }
    document.querySelectorAll('input[name=font]').forEach(function (r) {
        r.addEventListener('change', function () { html.style.setProperty('--tb-font-sans-serif', r.value ? stacks[r.value] : defStack); });
    });
    function pick(id, attr, fallback) { var el = document.getElementById(id); if (!el) { return; } el.addEventListener('change', function () { html.setAttribute(attr, el.value || fallback); }); }
    pick('my-density', 'data-uv-density', def.density);
    pick('my-line_spacing', 'data-uv-lines', def.line_spacing);
    var motion = document.getElementById('my-motion');
    motion.addEventListener('change', function () { if (motion.checked) { html.setAttribute('data-uv-motion', 'reduce'); } else { html.removeAttribute('data-uv-motion'); } });
    var theme = document.getElementById('my-theme');
    theme.addEventListener('change', function () {
        var m = theme.value || def.theme, d = m === 'auto' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : m;
        html.setAttribute('data-bs-theme', d); html.setAttribute('data-topbar', d === 'dark' ? 'dark' : 'light');
    });
})();
</script>
@endsection
