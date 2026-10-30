@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $title, 'icon' => 'ri-lock-line'])
    <div class="row justify-content-center"><div class="col-lg-7">
        <div class="card border-danger-subtle">
            <div class="card-body text-center py-4">
                <i class="ri-lock-2-line text-danger" style="font-size:3rem"></i>
                <h5 class="mt-2">{{ $title }}</h5>
                <p class="text-muted mb-3">{{ $reason }}</p>
                @include('fees.partials.standing', ['standing' => $standing])
                <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                    <a href="{{ route('my.fees') }}" class="btn btn-primary"><i class="ri-wallet-3-line me-1"></i>Pay my fees</a>
                    @if (\App\Services\Fees\LoanService::enabled())<a href="{{ route('my.loans') }}" class="btn btn-soft-primary"><i class="ri-hand-coin-line me-1"></i>Apply for a school loan</a>@endif
                </div>
            </div>
        </div>
    </div></div>
</div></div></div>
@endsection
