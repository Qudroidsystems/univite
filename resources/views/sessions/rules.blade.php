@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Session rules</h4></div>
            @include('academics.partials.alerts')
            <form method="POST" action="{{ route('sessions.rules.update') }}" class="card">
                @csrf @method('PUT')
                <div class="card-body row g-3">
                    <p class="text-muted mb-0">Rules that differ between universities. The defaults suit most Nigerian universities; confirm each one for this university.</p>
                    @foreach ($labels as $k => [$l, $h])
                        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="{{ $k }}" value="0"><input class="form-check-input" type="checkbox" name="{{ $k }}" value="1" id="s-{{ $k }}" @checked($s[$k] === '1')><label class="form-check-label" for="s-{{ $k }}">{{ $l }}</label></div>@if ($h)<div class="form-text">{{ $h }}</div>@endif</div>
                    @endforeach
                    <div class="col-md-6"><label class="form-label">Late registration lasts (days)</label><input type="number" name="late_registration_days" min="1" max="120" class="form-control @error('late_registration_days') is-invalid @enderror" value="{{ old('late_registration_days', $s['late_registration_days']) }}" required>@error('late_registration_days')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
                @can('Edit session rules')<div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save rules</button></div>@endcan
            </form>
        </div>
    </div>
</div>
@endsection
