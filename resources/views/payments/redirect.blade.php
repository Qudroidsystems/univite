<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Opening the payment page…</title>
<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f3f3f9;color:#212529}main{text-align:center;padding:16px}</style></head>
<body><main>
    <p>Opening the payment page…</p>
    <form id="gateway" method="POST" action="{{ $redirect->url }}">
        @foreach ($redirect->fields as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
        <noscript><button type="submit">Continue to payment</button></noscript>
    </form>
</main><script>document.getElementById('gateway').submit();</script></body></html>
