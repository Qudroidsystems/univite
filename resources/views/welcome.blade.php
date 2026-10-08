@extends('layouts.public')

@section('title', 'Welcome')

@section('content')
@php $open = \App\Services\ApplicationSettings::isOpen(); @endphp
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="text-center my-4">
            <h2 class="mb-1">{{ config('app.name') }}</h2>
            <p class="text-muted">Apply for admission, check your application, or sign in to your portal.</p>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card pub-card h-100"><div class="card-body text-center">
                    <i class="ri-file-edit-line fs-1 text-primary"></i>
                    <h5 class="mt-2">Apply for admission</h5>
                    <p class="text-muted">{{ $open ? 'Applications are open. Start your application online.' : 'Applications are currently closed.' }}</p>
                    <a href="{{ route('apply.register') }}" class="btn btn-primary">{{ $open ? 'Start application' : 'Read more' }}</a>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card pub-card h-100"><div class="card-body text-center">
                    <i class="ri-login-circle-line fs-1 text-primary"></i>
                    <h5 class="mt-2">Continue my application</h5>
                    <p class="text-muted">Already started? Sign in with your email or JAMB number to finish, print your slip or see your status.</p>
                    <a href="{{ route('apply.login') }}" class="btn btn-soft-primary">Continue</a>
                    <a href="{{ route('admission-status') }}" class="btn btn-light">Check admission status</a>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card pub-card h-100"><div class="card-body text-center">
                    <i class="ri-user-star-line fs-1 text-primary"></i>
                    <h5 class="mt-2">Students &amp; staff</h5>
                    <p class="text-muted">Admitted students and staff sign in here.</p>
                    <a href="{{ route('login') }}" class="btn btn-soft-primary">Sign in</a>
                </div></div>
            </div>
        </div>
    </div>
</div>
@endsection
