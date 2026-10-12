@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Institution profile</h4></div>
            @include('academics.partials.alerts')
            <p class="text-muted">These details appear across the portal: the menu, sign-in and application pages, and every printed slip, receipt and transcript.</p>
            @php $can = auth()->user()->can('Edit institution profile'); @endphp
            <form method="POST" action="{{ route('institution.update') }}" enctype="multipart/form-data" data-no-ajax>
                @csrf @method('PUT')
                @foreach ($groups as $group => $fields)
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">{{ $group }}</h6></div>
                        <div class="card-body row g-3">
                            @foreach ($fields as $key => [, $label, $type, $help])
                                @php $v = old($key, $values[$key]); @endphp
                                <div class="{{ in_array($type, ['textarea'], true) || $key === 'name' ? 'col-12' : ($type === 'image' ? 'col-md-6' : 'col-md-6') }}">
                                    <label class="form-label" for="f-{{ $key }}">{{ $label }}@if ($key === 'name') <span class="text-danger">*</span>@endif</label>
                                    @if ($type === 'textarea')
                                        <textarea id="f-{{ $key }}" name="{{ $key }}" rows="2" class="form-control @error($key) is-invalid @enderror" @disabled(! $can)>{{ $v }}</textarea>
                                    @elseif ($type === 'image')
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="border rounded d-flex align-items-center justify-content-center bg-light" style="width:72px;height:72px">
                                                @if ($values[$key])<img src="{{ \App\Support\Institution::image($key) }}" alt="" style="max-width:68px;max-height:68px">@else<i class="ri-image-line fs-3 text-muted"></i>@endif
                                            </div>
                                            <div class="flex-grow-1">
                                                <input id="f-{{ $key }}" type="file" name="{{ $key }}" accept="image/*" class="form-control form-control-sm @error($key) is-invalid @enderror" @disabled(! $can)>
                                                @if ($values[$key] && $can)<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_{{ $key }}" value="1" id="r-{{ $key }}"><label class="form-check-label fs-12" for="r-{{ $key }}">Remove</label></div>@endif
                                            </div>
                                        </div>
                                    @elseif ($type === 'color')
                                        <input id="f-{{ $key }}" type="color" name="{{ $key }}" value="{{ $v ?: '#405189' }}" class="form-control form-control-color @error($key) is-invalid @enderror" @disabled(! $can)>
                                    @else
                                        <input id="f-{{ $key }}" type="{{ ['email' => 'email', 'url' => 'url', 'year' => 'number'][$type] ?? 'text' }}" name="{{ $key }}" value="{{ $v }}" class="form-control @error($key) is-invalid @enderror" @disabled(! $can) @if ($key === 'name') required @endif>
                                    @endif
                                    @error($key)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    @if ($help)<div class="form-text">{{ $help }}</div>@endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                @if ($can)<div class="text-end mb-4"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save</button></div>@endif
            </form>
        </div>
    </div>
</div>
@endsection
