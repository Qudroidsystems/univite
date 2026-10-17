@php($l = $leaders)
<div class="card uv-leaders">
    <div class="card-body py-3">
        <div class="row g-3 align-items-center">
            <div class="col-lg-4 uv-leader-in" style="--uv-i:0">
                <div class="text-muted fs-11 text-uppercase fw-semibold">{{ $label }}</div>
                <div class="fs-15 fw-semibold">{{ $l->department }}</div>
                @if ($l->faculty)<div class="text-muted fs-13"><i class="ri-building-2-line me-1"></i>{{ $l->faculty }}</div>@endif
            </div>
            @foreach ([$l->hod ?? 'Head of Department', $l->dean ?? 'Dean'] as $i => $p)
                <div class="col-sm-6 col-lg-4 uv-leader-in" style="--uv-i:{{ $i + 1 }}">
                    @if (is_string($p))
                        <div class="text-muted fs-11 text-uppercase fw-semibold">{{ $p }}</div>
                        <div class="text-muted fs-13">Not assigned yet</div>
                    @else
                        <div class="d-flex align-items-center gap-2" style="min-width:0">
                            @if ($p->photoUrl)
                                <img src="{{ $p->photoUrl }}" alt="" class="rounded-circle object-fit-cover flex-shrink-0" style="width:42px;height:42px">
                            @else
                                <span class="avatar-sm flex-shrink-0" style="width:42px;height:42px"><span class="avatar-title rounded-circle bg-primary-subtle text-primary fs-14">{{ $p->initials }}</span></span>
                            @endif
                            <div class="lh-sm" style="min-width:0">
                                <div class="text-muted fs-11 text-uppercase fw-semibold">{{ $p->role }}</div>
                                <div class="fw-semibold text-truncate">{{ $p->name }}</div>
                                <div class="text-muted fs-12 text-truncate">{{ collect([$p->staffNumber, $p->designation])->filter()->implode(' · ') }}</div>
                                @if ($p->email)<div class="fs-12 text-truncate"><a href="mailto:{{ $p->email }}" title="{{ $p->email }}">{{ $p->email }}</a></div>@endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
