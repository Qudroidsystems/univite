{{-- NIN, religion, marital status, blood group, genotype and guardian email: shared by the biodata step and My profile.
     Pass $field (fn(key) => value), $ro ('readonly' or ''), $settings (App\Support\StudentSettings). --}}
@php $lk = fn ($list) => \App\Support\Lookups::options($list); @endphp
@if ($settings->on('show_nin'))
    <div class="col-md-4"><label class="form-label">National Identity Number (NIN)</label><input name="nin" value="{{ $field('nin') }}" class="form-control" inputmode="numeric" maxlength="11" placeholder="11 digits" @if ($settings->on('require_nin')) required @endif {{ $ro }}></div>
@endif
@foreach (['religion' => 'Religion', 'marital_status' => 'Marital status', 'blood_group' => 'Blood group', 'genotype' => 'Genotype'] as $k => $label)
    <div class="col-md-4"><label class="form-label">{{ $label }}</label>
        <select name="{{ $k }}" class="form-select" {{ $ro ? 'disabled' : '' }}><option value="">Not given</option>@foreach ($lk($k) as $v => $l)<option value="{{ $v }}" @selected((string) $field($k) === (string) $v)>{{ $l }}</option>@endforeach</select></div>
@endforeach
<div class="col-md-4"><label class="form-label">Guardian email</label><input type="email" name="next_of_kin_email" value="{{ $field('next_of_kin_email') }}" class="form-control" {{ $ro }}></div>
