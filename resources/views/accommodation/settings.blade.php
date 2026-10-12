@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Accommodation rules', 'icon' => 'ri-settings-3-line', 'back' => [route('hostels.index'), 'Hostels']])
    @include('academics.partials.alerts')
    <form method="POST" action="{{ route('accommodation.settings.save') }}" class="card"><div class="card-body">@csrf @method('PUT')
        @include('partials.settings-fields', ['group' => $group, 'values' => $values])
        <p class="text-muted fs-12 mt-3 mb-0">Beds not paid for by the deadline are released each morning (the Laravel scheduler must be running). A bed with part-payment is kept for the bursary to settle.</p>
    </div><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div></form>
</div></div></div>
@endsection
