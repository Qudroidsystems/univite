{{-- The institution's logo, or its initial in a coloured square. $size in px. --}}
@php $logo = \App\Support\Institution::image('logo'); $size ??= 40; @endphp
@if ($logo)<img src="{{ $logo }}" alt="" style="height:{{ $size }}px;width:auto">@else<span class="brand-mark" style="width:{{ $size }}px;height:{{ $size }}px">{{ \App\Support\Institution::initials() }}</span>@endif
