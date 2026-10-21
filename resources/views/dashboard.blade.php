@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Dashboard</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0"><li class="breadcrumb-item active">Dashboard</li></ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 bg-primary-subtle">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="mb-1">Welcome, {{ auth()->user()->name }}</h5>
                        @if ($currentSession)
                            <p class="mb-0 text-muted">
                                Current session <strong>{{ $currentSession->name }}</strong>
                                @if ($currentSemester) · <strong>{{ $currentSemester->name }}</strong> @endif
                            </p>
                        @else
                            <p class="mb-0 text-muted">No current session yet.
                                @can('View academic sessions') <a href="{{ route('academics.sessions.index') }}">Create one to get started</a>. @endcan
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Figures and the setup checklist count whole tables: they load once the page is showing. --}}
            <div data-uv-lazy="{{ route('dashboard.summary') }}" data-uv-select="#uv-dash-stats"><div class="row">@for ($i = 0; $i < 6; $i++)<div class="col-xl col-md-4 col-6"><div class="card"><div class="uv-skeleton"><span></span><span></span></div></div></div>@endfor</div></div>

            @include('dashboard.menu-cards', ['sections' => $cardSections ?? []])

            <div class="card mt-3">
                <div class="card-header"><h6 class="card-title mb-0">Setup checklist</h6></div>
                <div class="card-body p-0" data-uv-lazy="{{ route('dashboard.summary') }}" data-uv-select="#uv-dash-setup"></div>
            </div>
        </div>
    </div>
</div>
@endsection
