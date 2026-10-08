@extends('layouts.public')

@section('title', 'Continue my application')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card pub-card">
            <div class="card-body p-4">
                <h5 class="mb-1">Continue my application</h5>
                <p class="text-muted">Use the email, JAMB registration number or application number and the password you chose when you applied.</p>
                @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
                <form method="POST" action="{{ route('apply.login.attempt') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="login">Email, JAMB number or application number</label>
                        <input id="login" name="login" value="{{ old('login') }}" class="form-control @error('login') is-invalid @enderror" required autofocus>
                        @error('login')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input id="password" type="password" name="password" class="form-control" required>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Continue</button>
                </form>
                <p class="text-muted fs-12 mt-3 mb-0">Admitted already? Students sign in at <a href="{{ route('login') }}">Student / staff sign in</a> with their email and the same password.</p>
            </div>
        </div>
    </div>
</div>
@endsection
