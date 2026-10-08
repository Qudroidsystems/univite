@extends('layouts.public')

@section('title', 'Admission status')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="card pub-card">
            <div class="card-body p-4">
                <h5 class="mb-1">Check your admission status</h5>
                <p class="text-muted">Enter your JAMB registration number and your surname exactly as you used when applying.</p>

                @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

                <form method="POST" action="{{ route('admission-status.check') }}" class="row g-3">
                    @csrf
                    <div class="col-md-6"><label class="form-label">JAMB registration number</label><input name="jamb_reg_no" value="{{ $old['jamb_reg_no'] ?? old('jamb_reg_no') }}" class="form-control text-uppercase" maxlength="20" required></div>
                    <div class="col-md-6"><label class="form-label">Surname</label><input name="last_name" value="{{ $old['last_name'] ?? old('last_name') }}" class="form-control" required></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Check status</button></div>
                </form>

                @if ($result === false)
                    <div class="alert alert-warning mt-4 mb-0">We could not find an application with those details. Check them and try again.</div>
                @elseif ($result)
                    <hr>
                    <h6 class="mb-1">{{ $result->full_name }}</h6>
                    <div class="text-muted mb-3">{{ $result->programme->name }}</div>
                    @switch($result->status)
                        @case('admitted')
                            <div class="alert alert-success mb-0">
                                <strong>Congratulations, you have been offered admission.</strong>
                                @if ($result->admission_batch) <div>{{ $result->admission_batch }}</div> @endif
                                <div class="mt-2">Sign in at <a href="{{ route('login') }}">Student / staff sign in</a> with <strong>{{ $result->email }}</strong> and the password you chose when you applied, then follow the steps on your "Getting started" page.</div>
                            </div>
                            @break
                        @case('rejected')
                            <div class="alert alert-danger mb-0">We are sorry. Your application was not successful this time.</div>
                            @break
                        @case('pending')
                            <div class="alert alert-info mb-0">Your application has been submitted and is under review. No decision has been made yet. Please check again later.</div>
                            @break
                        @default
                            <div class="alert alert-secondary mb-0">You have not submitted your application yet. <a href="{{ route('apply.login') }}">Continue your application</a>.</div>
                    @endswitch
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
