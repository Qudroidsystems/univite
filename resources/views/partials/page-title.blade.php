{{-- Page heading: $title, optional $icon, $back [url, label] and $subtitle. --}}
<div class="page-title-box d-sm-flex align-items-center justify-content-between gap-2 flex-wrap">
    <div>
        <h4 class="mb-0">@if (! empty($icon))<i class="{{ $icon }} me-1 align-middle"></i>@endif{{ $title }}</h4>
        @if (! empty($subtitle))<div class="text-muted fs-13 mt-1">{{ $subtitle }}</div>@endif
    </div>
    @if (! empty($back))<a href="{{ $back[0] }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>{{ $back[1] }}</a>@endif
</div>
