{{-- Admin-defined extra fields as Bootstrap columns. Vars: $cfTarget, $cfSubject (model|null), $cfSelfService (bool), $cfReadonly (bool) --}}
@php
    $cfDefs   = \App\Support\CustomFields::defs($cfTarget, $cfSelfService ?? false);
    $cfValues = ($cfSubject ?? null) ? \App\Support\CustomFields::valuesFor($cfSubject)->keyBy('custom_field_id') : collect();
    $cfRo     = ! empty($cfReadonly) ? 'disabled' : '';
    $cfLast   = null;
@endphp
@foreach ($cfDefs as $cf)
    @php
        $n   = $cf->inputName();
        $v   = $cfValues->get($cf->id);
        $val = old($n, $v?->value);
    @endphp
    @if ($cf->section && $cf->section !== $cfLast)
        @php $cfLast = $cf->section; @endphp
        <div class="col-12"><h6 class="text-uppercase text-muted fs-12 border-bottom pb-1 mb-0 mt-2">{{ $cf->section }}</h6></div>
    @endif
    <div class="col-md-{{ $cf->type === 'textarea' ? 12 : 4 }}">
        @if ($cf->type === 'checkbox')
            <div class="form-check mt-4">
                <input type="hidden" name="{{ $n }}" value="0">
                <input class="form-check-input" type="checkbox" id="cf-{{ $cf->id }}" name="{{ $n }}" value="1" @checked((bool) $val) {{ $cfRo }}>
                <label class="form-check-label" for="cf-{{ $cf->id }}">{{ $cf->label }}@if ($cf->is_required) <span class="text-danger">*</span>@endif</label>
            </div>
        @else
            <label class="form-label" for="cf-{{ $cf->id }}">{{ $cf->label }}@if ($cf->is_required) <span class="text-danger">*</span>@endif</label>
            @if ($cf->type === 'textarea')
                <textarea class="form-control @error($n) is-invalid @enderror" id="cf-{{ $cf->id }}" name="{{ $n }}" rows="3" {{ $cfRo }}>{{ $val }}</textarea>
            @elseif ($cf->type === 'select')
                <select class="form-select @error($n) is-invalid @enderror" id="cf-{{ $cf->id }}" name="{{ $n }}" {{ $cfRo }}>
                    <option value="">Select…</option>
                    @foreach ($cf->options ?? [] as $opt)<option value="{{ $opt }}" @selected((string) $val === (string) $opt)>{{ $opt }}</option>@endforeach
                </select>
            @elseif ($cf->type === 'file')
                @if ($v?->file_path)
                    <div class="small mb-1"><a href="{{ route($cfFileRoute ?? 'custom-fields.download', $v->id) }}" target="_blank">{{ $v->original_name }}</a></div>
                @endif
                @unless ($cfRo)
                    <input type="file" class="form-control @error($n) is-invalid @enderror" id="cf-{{ $cf->id }}" name="{{ $n }}" accept=".{{ str_replace(',', ',.', $cf->accept) }}">
                    <div class="form-text text-success d-none" data-file-for="{{ $n }}"></div>
                @endunless
            @else
                <input type="{{ $cf->type === 'number' ? 'number' : ($cf->type === 'date' ? 'date' : 'text') }}" @if ($cf->type === 'number') step="any" @endif
                       class="form-control @error($n) is-invalid @enderror" id="cf-{{ $cf->id }}" name="{{ $n }}" value="{{ $val }}" {{ $cfRo }}>
            @endif
        @endif
        @error($n) <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        @if ($cf->help_text) <div class="form-text">{{ $cf->help_text }}</div> @endif
    </div>
@endforeach
