@extends('layouts.auth')

@section('title', 'Reset password')

@section('content')
    <h5 class="mb-1">Forgot your password?</h5>
    <p class="text-muted mb-4">Enter your email and we'll send you a reset link.</p>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="email">
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3">Send reset link</button>
        <div class="text-center"><a href="{{ route('login') }}" class="text-muted fs-13">Back to sign in</a></div>
    </form>
@endsection
