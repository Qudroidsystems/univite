@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $hostel->exists ? 'Edit ' . $hostel->name : 'New hostel', 'icon' => 'ri-hotel-bed-line', 'back' => [$hostel->exists ? route('hostels.show', $hostel) : route('hostels.index'), 'Hostels']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ $hostel->exists ? route('hostels.update', $hostel) : route('hostels.store') }}" class="card">@csrf @if ($hostel->exists) @method('PUT') @endif
        <div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name', $hostel->name) }}" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Code</label><input name="code" value="{{ old('code', $hostel->code) }}" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">For</label><select name="gender" class="form-select">@foreach (\App\Models\Hostel::GENDERS as $k => $l)<option value="{{ $k }}" @selected(old('gender', $hostel->gender) === $k)>{{ $l }} students</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label">Warden</label><input name="warden" value="{{ old('warden', $hostel->warden?->staffProfile?->staff_number ?? $hostel->warden?->email) }}" class="form-control" placeholder="Staff number or email"></div>
            <div class="col-md-6"><label class="form-label">Fee per bed for {{ $session?->name ?? 'the current session' }} (₦)</label><input name="rate" type="number" step="0.01" min="0" value="{{ old('rate', $hostel->exists ? $hostel->rateFor($session) : '') }}" class="form-control" @disabled(! $session)><div class="form-text">0 or empty means free. Students pay under My Fees before their bed is confirmed.</div></div>
            <div class="col-12"><label class="form-label">Description</label><textarea name="description" rows="2" class="form-control">{{ old('description', $hostel->description) }}</textarea></div>
            <div class="col-12"><div class="fw-semibold mb-1">Which students may be placed here</div>@include('partials.audience', ['audience' => $hostel->audienceData(), 'who' => ['students'], 'genders' => false])</div>
            <div class="col-12"><div class="form-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" id="act" class="form-check-input" @checked(old('is_active', $hostel->is_active))><label class="form-check-label" for="act">In use (students can be placed here)</label></div></div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <button class="btn btn-primary">Save</button>
        </div>
    </form>
    @if ($hostel->exists)
        <form method="POST" action="{{ route('hostels.destroy', $hostel) }}" class="text-end" onsubmit="return confirm('Remove this hostel and its rooms?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove hostel</button></form>
    @endif
</div></div></div>
@endsection
