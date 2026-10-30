{{-- The look this person chose (or the school's default): font, text size, light or dark, menu, spacing, motion. See App\Support\Appearance. --}}
@php
    $uvLook = app(\App\Support\Appearance::class)->page(auth()->user());
    $uvP = $uvLook['prefs'];
@endphp
<link rel="preconnect" href="https://fonts.googleapis.com/">
<link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>
@if ($uvLook['fontsUrl'])<link href="{{ $uvLook['fontsUrl'] }}" rel="stylesheet" id="uvFontsLink">@endif
{{-- The theme's script rewrites the link with this id to its own font on load, so it gets a harmless stand-in. --}}
<link rel="preconnect" href="https://fonts.gstatic.com/" id="fontsLink">
<style id="uv-appearance">
    html[data-theme] { --tb-font-sans-serif: {!! $uvLook['stack'] !!}; --cb-font: var(--tb-font-sans-serif); --cb-font-display: {!! $uvLook['headingStack'] ?? 'var(--tb-font-sans-serif)' !!}; --tb-font-base: 0.8125rem; --uv-scale: {{ $uvLook['scale'] }}; font-size: calc(16px * var(--uv-scale)); }
    @if ($uvLook['headingStack'])
    h1, h2, h3, h4, h5, h6, .h1, .h2, .h3, .h4, .h5, .h6, .card-title, .page-title-box h4 { font-family: {!! $uvLook['headingStack'] !!}; }
    @endif
</style>
<meta name="uv-appearance" content="{{ json_encode(['theme' => $uvP->theme, 'menu' => $uvP->menu, 'save' => auth()->check() ? route('my.appearance.quick', [], false) : null]) }}">
<script>
    (function () {
        var h = document.documentElement;
        h.setAttribute('data-uv-density', @json($uvP->density));
        h.setAttribute('data-uv-lines', @json($uvP->lineSpacing));
        if (@json((bool) $uvP->reduceMotion)) { h.setAttribute('data-uv-motion', 'reduce'); }
        // The saved choice on the server wins over the one remembered by this browser, so it follows the person to every device.
        try {
            @if (auth()->check())
            localStorage.setItem('uv-theme', @json($uvP->theme));
            if (@json($uvP->menu) === 'top') { localStorage.setItem('uv-menu', 'top'); } else { localStorage.removeItem('uv-menu'); }
            @elseif ($uvP->theme !== 'light')
            if (!localStorage.getItem('uv-theme')) { localStorage.setItem('uv-theme', @json($uvP->theme)); }
            @endif
        } catch (e) { /* storage blocked: the defaults below still apply */ }
    })();
</script>
