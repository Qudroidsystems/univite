@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1000px">
    @include('partials.page-title', ['title' => 'Library rules', 'icon' => 'ri-settings-3-line', 'back' => [route('library.desk'), 'Desk']])
    @include('academics.partials.alerts')
    <form method="POST" action="{{ route('library.settings.save') }}" class="card"><div class="card-body">@csrf @method('PUT')
        @include('partials.settings-fields', ['group' => $group, 'values' => $values])
    </div><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div></form>
</div></div></div>
@endsection
