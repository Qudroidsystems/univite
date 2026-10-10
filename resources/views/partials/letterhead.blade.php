{{-- Printed document header: logo, name, address and contacts. Pass $subtitle (e.g. "Payment receipt"). --}}
@php $I = \App\Support\Institution::class; @endphp
<div class="d-flex align-items-center justify-content-center gap-3 mb-3 pb-2 border-bottom text-center">
    @if ($I::image('logo'))<img src="{{ $I::image('logo') }}" alt="" style="height:64px;width:auto">@endif
    <div>
        <h4 class="mb-0 text-uppercase">{{ $I::get('name') }}</h4>
        @if ($I::addressLine())<div class="fs-12 text-muted">{{ $I::addressLine() }}</div>@endif
        @php $contacts = collect([$I::get('phone'), $I::get('email'), $I::get('website')])->filter()->implode(' · '); @endphp
        @if ($contacts)<div class="fs-12 text-muted">{{ $contacts }}</div>@endif
        @if (! empty($subtitle))<div class="fw-semibold mt-1">{{ $subtitle }}</div>@endif
    </div>
</div>
