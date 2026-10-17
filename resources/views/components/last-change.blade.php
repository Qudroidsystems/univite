@if ($change)
    <div class="d-flex align-items-center gap-2 text-nowrap uv-last-change">
        @if ($change->photoUrl)
            <img src="{{ $change->photoUrl }}" alt="" class="rounded-circle object-fit-cover" style="width:28px;height:28px">
        @else
            <span class="avatar-xs"><span class="avatar-title rounded-circle bg-primary-subtle text-primary fs-11">{{ $change->initials }}</span></span>
        @endif
        <div class="lh-sm">
            <div class="fs-12 fw-medium">{{ $change->staffNumber ?: $change->name }}</div>
            <div class="fs-11 text-muted" title="{{ $change->action }} {{ $change->at->format('l j F Y, g:i a') }}">{{ $change->action }} {{ $change->at->diffForHumans(short: true) }}</div>
        </div>
        <button type="button" class="btn btn-sm btn-ghost-info p-0 px-1 uv-who" title="About {{ $change->name }}"
                data-url="{{ route('activity.show', [$change->type, $change->id]) }}"><i class="ri-information-line fs-16"></i></button>
    </div>
@else
    <span class="text-muted">—</span>
@endif
