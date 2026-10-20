{{-- The portal's menu as cards: a card per group of pages (with its pages listed), and tiles for single pages. --}}
@foreach ($sections as $section)
    @php
        $groups = collect($section->items)->filter(fn ($i) => $i instanceof \App\Data\Navigation\MenuGroup);
        $links = collect($section->items)->filter(fn ($i) => $i instanceof \App\Data\Navigation\MenuLink);
    @endphp
    <div class="d-flex align-items-center gap-2 mt-2 mb-2"><h6 class="text-uppercase text-muted fs-12 fw-semibold mb-0 letter-spacing">{{ $section->title }}</h6><div class="flex-grow-1 border-top"></div></div>
    @if ($links->isNotEmpty())
        <div class="row g-3 mb-3">
            @foreach ($links as $l)
                <div class="col-xxl-2 col-lg-3 col-md-4 col-6">
                    <a href="{{ $l->url }}" class="card uv-tile h-100 mb-0 text-decoration-none">
                        <div class="card-body text-center py-3 position-relative">
                            @if ($l->badge)<span class="badge bg-danger position-absolute top-0 end-0 m-2">{{ $l->badge }}</span>@endif
                            <span class="uv-tile-icon"><i class="{{ $l->icon }}"></i></span>
                            <div class="fw-medium text-body mt-2 fs-13">{{ $l->label }}</div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
    @if ($groups->isNotEmpty())
        <div class="row g-3 mb-3">
            @foreach ($groups as $g)
                @php $first = array_slice($g->links, 0, 6); $more = array_slice($g->links, 6); @endphp
                <div class="col-xxl-3 col-lg-4 col-md-6">
                    <div class="card uv-menu-card h-100 mb-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="uv-tile-icon uv-tile-icon-sm"><i class="{{ $g->icon }}"></i></span>
                                <div><h6 class="mb-0">{{ $g->label }}</h6><div class="text-muted fs-12">{{ count($g->links) }} {{ \Illuminate\Support\Str::plural('page', count($g->links)) }}</div></div>
                            </div>
                            <ul class="list-unstyled mb-0 uv-menu-links">
                                @foreach ($first as $l)<li><a href="{{ $l->url }}"><i class="ri-arrow-right-s-line"></i>{{ $l->label }}</a></li>@endforeach
                            </ul>
                            @if ($more)
                                <div class="collapse" id="more-{{ $g->id }}-{{ $loop->index }}"><ul class="list-unstyled mb-0 uv-menu-links">@foreach ($more as $l)<li><a href="{{ $l->url }}"><i class="ri-arrow-right-s-line"></i>{{ $l->label }}</a></li>@endforeach</ul></div>
                                <a class="fs-12 d-inline-block mt-1" data-bs-toggle="collapse" href="#more-{{ $g->id }}-{{ $loop->index }}" role="button">+ {{ count($more) }} more</a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endforeach
