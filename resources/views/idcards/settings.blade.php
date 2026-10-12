@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Identity card design', 'icon' => 'ri-palette-line', 'back' => [route('id-cards.index'), 'Identity cards']])
    @include('academics.partials.alerts')
    <form method="POST" action="{{ route('id-cards.settings.save') }}" class="card"><div class="card-body">@csrf @method('PUT')
        @include('partials.settings-fields', ['group' => $group, 'values' => $values])
        <p class="text-muted fs-12 mt-3 mb-0">The logo, name, motto and the Registrar's signature come from the institution profile. Changes apply to cards issued from now on; reissue a card to print it with the new design.</p>
    </div><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div></form>
</div></div></div>
@endsection
