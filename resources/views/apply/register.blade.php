@extends('layouts.public')

@section('title', 'Apply')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        @if (! $open)
            <div class="card pub-card"><div class="card-body text-center py-5">
                <i class="ri-lock-line fs-1 text-muted"></i>
                <h4 class="mt-2">Applications are closed</h4>
                <p class="text-muted mb-3">{{ $closedWhy }}</p>
                <a href="{{ route('apply.login') }}" class="btn btn-soft-primary">Continue an application I already started</a>
            </div></div>
        @else
            <div class="card pub-card">
                <div class="card-body p-4">
                    <h4 class="mb-1">Apply for admission — {{ $session->name }}</h4>
                    <p class="text-muted">Start your application below. You will choose a password so you can come back and finish later.
                        @if ($fee > 0) The screening fee is <strong>₦{{ number_format($fee, 2) }}</strong>, paid after you register. @endif</p>

                    @if ($instructions)
                        <div class="alert alert-info" style="white-space:pre-line">{{ $instructions }}</div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                    @endif

                    <form method="POST" action="{{ route('apply.store') }}" class="row g-3">
                        @csrf
                        <div class="col-md-4"><label class="form-label">First name *</label><input name="first_name" value="{{ old('first_name') }}" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Surname *</label><input name="last_name" value="{{ old('last_name') }}" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Other names</label><input name="other_names" value="{{ old('other_names') }}" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" value="{{ old('email') }}" class="form-control" required>
                            <div class="form-text">Use an email you check often. We contact you here.</div></div>
                        <div class="col-md-6"><label class="form-label">Phone number *</label><input name="phone_number" value="{{ old('phone_number') }}" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">JAMB registration number *</label><input name="jamb_reg_no" value="{{ old('jamb_reg_no') }}" class="form-control text-uppercase" maxlength="10" placeholder="12345678AB" required></div>
                        <div class="col-md-6"><label class="form-label">How are you applying? *</label>
                            <select name="entry_mode" id="entry_mode" class="form-select" required>
                                @foreach ($entryModes as $mode)
                                    <option value="{{ $mode->code }}" data-utme="{{ $mode->uses_utme ? 1 : 0 }}" @selected(old('entry_mode', $entryModes->first()->code) === $mode->code)>{{ $mode->name }}@if ($mode->entry_level) ({{ $mode->entry_level }} level)@endif</option>
                                @endforeach
                            </select></div>
                        <div class="col-md-6" id="score-box"><label class="form-label">UTME score *</label><input type="number" name="jamb_score" value="{{ old('jamb_score') }}" min="0" max="400" class="form-control">
                            <div class="form-text">Minimum {{ $minScore }}.</div></div>
                        <div class="col-md-6"><label class="form-label">Programme *</label>
                            <select name="programme_id" class="form-select" required>
                                <option value="">Select…</option>
                                @foreach ($programmes->groupBy(fn ($p) => $p->department->faculty->name) as $faculty => $group)
                                    <optgroup label="{{ $faculty }}">@foreach ($group as $p)<option value="{{ $p->id }}" @selected((string) old('programme_id') === (string) $p->id)>{{ $p->name }}</option>@endforeach</optgroup>
                                @endforeach
                            </select></div>
                        <div class="col-md-6"><label class="form-label">Choose a password *</label><input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password"><div class="form-text">At least 8 characters.</div></div>
                        <div class="col-md-6"><label class="form-label">Confirm password *</label><input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password"></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">Register and continue</button>
                            <a href="{{ route('apply.login') }}" class="btn btn-light ms-2">I already have an application</a></div>
                    </form>
                </div>
            </div>
        @endif
    </div>
</div>
@push('scripts')
<script>
    const mode = document.getElementById('entry_mode'), box = document.getElementById('score-box');
    if (mode) { const sync = () => { box.style.display = mode.selectedOptions[0]?.dataset.utme === '1' ? '' : 'none'; }; mode.addEventListener('change', sync); sync(); }
</script>
@endpush
@endsection
