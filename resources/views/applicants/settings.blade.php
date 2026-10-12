@extends('layouts.master')

@section('content')
@php $canEdit = auth()->user()->can('Edit application settings'); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row"><div class="col-12"><div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-settings-3-line me-1 align-middle"></i>Application Settings</h4>
                <div class="page-title-right"><ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('applicants.index') }}">Applicants</a></li>
                    <li class="breadcrumb-item active">Settings</li></ol></div>
            </div></div></div>

            @include('academics.partials.alerts')

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="alert {{ $open ? 'alert-success' : 'alert-secondary' }}">
                                Online applications are <strong>{{ $open ? 'open' : 'closed' }}</strong>.
                                @if ($open) The public form is at <a href="{{ route('apply.register') }}" target="_blank">{{ route('apply.register') }}</a>. @endif
                            </div>
                            <form method="POST" action="{{ route('applicants.settings.update') }}" class="row g-3">
                                @csrf @method('PUT')
                                <div class="col-12">
                                    <div class="form-check form-switch form-switch-lg">
                                        <input type="hidden" name="open" value="0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="open" name="open" value="1" @checked(old('open', $enabled)) @disabled(! $canEdit)>
                                        <label class="form-check-label" for="open">Accept online applications</label>
                                    </div>
                                </div>
                                <div class="col-md-6"><label class="form-label">Session applicants apply for</label>
                                    <select name="academic_session_id" data-lookup="sessions" class="form-select @error('academic_session_id') is-invalid @enderror" @disabled(! $canEdit)>
                                        <option value="">Select…</option>
                                        @foreach ($sessions as $id => $name)<option value="{{ $id }}" @selected((string) old('academic_session_id', $sessionId) === (string) $id)>{{ $name }}</option>@endforeach
                                    </select>
                                    @error('academic_session_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label">Closing date</label><input type="date" name="deadline" value="{{ old('deadline', $deadline) }}" class="form-control" @disabled(! $canEdit)>
                                    <div class="form-text">Applications close automatically after this day. Leave empty for no deadline.</div></div>
                                <div class="col-md-6"><label class="form-label">Screening fee (₦)</label><input type="number" step="0.01" min="0" name="fee" value="{{ old('fee', $fee) }}" class="form-control @error('fee') is-invalid @enderror" required @disabled(! $canEdit)>
                                    <div class="form-text">Use 0 for no fee. Applicants cannot submit until it is paid.</div>
                                    @error('fee')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-md-6"><label class="form-label">Minimum UTME score</label><input type="number" min="0" max="400" name="min_utme_score" value="{{ old('min_utme_score', $minScore) }}" class="form-control" required @disabled(! $canEdit)></div>
                                <div class="col-12"><label class="form-label">Instructions shown on the application page</label><textarea name="instructions" rows="4" class="form-control" @disabled(! $canEdit)>{{ old('instructions', $instructions) }}</textarea></div>
                                <div class="col-12"><label class="form-label">How to pay the screening fee</label><textarea name="payment_instructions" rows="3" class="form-control" placeholder="Account name, bank, account number, and how to quote the payment reference" @disabled(! $canEdit)>{{ old('payment_instructions', $paymentInstructions) }}</textarea>
                                    <div class="form-text">Shown to applicants next to their payment reference.</div></div>
                                <div class="col-12"><label class="form-label">Bill item charged as the acceptance fee</label>
                                    <select name="acceptance_item_id" class="form-select" @disabled(! $canEdit)>
                                        <option value="">No acceptance fee</option>
                                        @foreach ($billItems as $id => $name)<option value="{{ $id }}" @selected((string) old('acceptance_item_id', $acceptanceItemId) === (string) $id)>{{ $name }}</option>@endforeach
                                    </select>
                                    <div class="form-text">When a newly admitted student accepts their offer, an invoice is raised from the school bills for this item (their programme, entry level and entry session). This item is left out of their session fees invoice.</div></div>
                                @if ($canEdit)<div class="col-12"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save settings</button></div>@endif
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
