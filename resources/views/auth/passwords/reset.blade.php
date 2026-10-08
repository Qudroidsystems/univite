@extends('layouts.auth')

@section('title', 'Choose a new password')

@section('content')
    <h5 class="mb-1">Choose a new password</h5>
    <p class="text-muted mb-4">Use at least 8 characters.</p>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input id="email" type="email" name="email" value="{{ $email ?? old('email') }}" class="form-control @error('email') is-invalid @enderror" required autocomplete="email">
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <input id="password" type="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password" autofocus>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <div class="mb-4">
            <label for="password-confirm" class="form-label">Confirm password</label>
            <input id="password-confirm" type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary w-100">Reset password</button>
    </form>
@endsection
