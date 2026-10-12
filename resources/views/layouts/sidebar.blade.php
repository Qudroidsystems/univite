{{-- The sidebar: sections with collapsible groups, built by App\Support\Navigation\Menu (see ShellComposer). --}}
@php
    $u = auth()->user();
    $logo = \App\Support\Institution::image('logo');
    $avatar = $u->student?->photo_url;
@endphp
<div class="app-menu navbar-menu">
    <div class="navbar-brand-box">
        <a href="{{ route('dashboard') }}" class="logo">
            <span class="logo-sm">@if ($logo)<img src="{{ $logo }}" alt="" class="uv-logo-img" style="height:34px">@else<span class="fs-4 fw-bold text-white">{{ \App\Support\Institution::initials() }}</span>@endif</span>
            <span class="logo-lg d-inline-flex align-items-center gap-2">@if ($logo)<img src="{{ $logo }}" alt="" class="uv-logo-img" style="height:46px">@endif<span class="fs-5 fw-bold text-white text-truncate" style="max-width:150px" title="{{ \App\Support\Institution::get('name') }}">{{ \App\Support\Institution::get('short_name') ?: \App\Support\Institution::get('name') }}</span></span>
        </a>
        <button type="button" class="btn btn-sm p-0 fs-3xl header-item float-end btn-vertical-sm-hover" id="vertical-hover">
            <i class="ri-record-circle-line"></i>
        </button>
    </div>

    <div id="scrollbar">
        <div class="container-fluid">
            <div id="two-column-menu"></div>
            <ul class="navbar-nav" id="navbar-nav">
                @foreach ($menuSections as $section)
                    <li class="menu-title">@unless ($loop->first)<i class="ri-more-fill"></i> @endunless<span>{{ $section->title }}</span></li>
                    @foreach ($section->items as $item)
                        @if ($item instanceof \App\Data\Navigation\MenuGroup)
                            @php $open = $item->open(); @endphp
                            <li class="nav-item">
                                <a class="nav-link menu-link {{ $open ? 'nav-active-parent' : 'collapsed' }}" href="#{{ $item->id }}" data-bs-toggle="collapse" role="button" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $item->id }}">
                                    <i class="{{ $item->icon }}"></i> <span>{{ $item->label }}</span>
                                </a>
                                <div class="collapse menu-dropdown {{ $open ? 'show' : '' }}" id="{{ $item->id }}">
                                    <ul class="nav nav-sm flex-column">
                                        @foreach ($item->links as $link)
                                            <li class="nav-item"><a href="{{ $link->url }}" class="nav-link {{ $link->active ? 'active nav-active-child' : '' }}">{{ $link->label }}</a></li>
                                        @endforeach
                                    </ul>
                                </div>
                            </li>
                        @else
                            <li class="nav-item">
                                <a class="nav-link menu-link {{ $item->active ? 'active nav-active-parent' : '' }}" href="{{ $item->url }}">
                                    <i class="{{ $item->icon }}"></i> <span>{{ $item->label }}</span>@if ($item->badge)<span class="badge bg-danger ms-auto">{{ $item->badge }}</span>@endif
                                </a>
                            </li>
                        @endif
                    @endforeach
                @endforeach
            </ul>
        </div>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-footer-user">
            @if ($avatar)<img src="{{ $avatar }}" alt="">@else<span class="sidebar-footer-avatar-initials">{{ $u->initials }}</span>@endif
            <div class="sidebar-footer-user-info">
                <div class="sidebar-footer-user-name">{{ $u->name }}</div>
                <div class="sidebar-footer-user-role">{{ $u->roles->first()->name ?? ($u->student ? 'Student' : 'User') }}</div>
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}" data-no-ajax>@csrf
            <button type="submit" class="sidebar-logout-btn"><i class="ri-logout-box-r-line"></i><span>Sign Out</span></button>
        </form>
    </div>
    <div class="sidebar-background"></div>
</div>
