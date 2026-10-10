{{-- Signature block for printed documents. $role: "registrar" or "bursar". --}}
@php
    $I = \App\Support\Institution::class;
    $role ??= 'registrar';
    $name = $I::get($role . '_name');
    $sig = $role === 'registrar' ? $I::image('registrar_signature') : null;
    $stamp = $I::image('stamp');
@endphp
@if ($name || $sig || $stamp)
    <div class="d-flex justify-content-between align-items-end mt-4">
        <div>@if ($stamp)<img src="{{ $stamp }}" alt="" style="height:80px;opacity:.85">@endif</div>
        <div class="text-center" style="min-width:220px">
            @if ($sig)<img src="{{ $sig }}" alt="" style="height:50px">@endif
            <div class="border-top pt-1">{{ $name ?: '' }}</div>
            <div class="fs-12 text-muted">{{ $role === 'registrar' ? 'Registrar' : 'Bursar' }}</div>
        </div>
    </div>
@endif
