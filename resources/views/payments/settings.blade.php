@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Payment settings', 'icon' => 'ri-bank-card-line', 'subtitle' => 'Online payment for students and applicants. Keys are stored encrypted and never shown again.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card"><div class="card-body d-flex flex-wrap gap-3">
        @foreach ($gateways as $g)
            <div><span class="badge {{ $g['on'] && $g['ready'] ? 'bg-success-subtle text-success' : ($g['on'] ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary') }}">{{ $g['on'] && $g['ready'] ? 'Live for payers' : ($g['on'] ? 'Keys missing' : 'Off') }}</span> {{ $g['label'] }}</div>
        @endforeach
    </div></div>

    <form method="POST" action="{{ route('payments.settings.update') }}" class="card" autocomplete="off"><div class="card-body">@csrf @method('PUT')
        @include('partials.settings-fields', ['group' => $group, 'values' => $values])
    </div><div class="card-footer text-end"><button class="btn btn-primary">Save</button></div></form>
</div></div></div>
@endsection
