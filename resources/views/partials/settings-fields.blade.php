{{-- Renders the fields of an App\Support\SettingsGroup: pass $group and $values. --}}
<div class="row g-3">
@foreach ($group->fields() as $key => $f)
    @php [$label, $type] = $f; $help = $f[3] ?? null; $v = $type === 'secret' ? null : old($key, $values[$key] ?? null); @endphp
    @if ($type === 'heading')
        <div class="col-12 {{ $loop->first ? '' : 'border-top pt-3 mt-4' }}"><h6 class="mb-0">{{ $label }}</h6>@if ($help)<div class="text-muted fs-13">{{ $help }}</div>@endif</div>
        @continue
    @endif
    <div class="{{ in_array($type, ['textarea']) ? 'col-12' : 'col-md-6' }}">
        @if ($type === 'switch')
            <div class="form-check form-switch mt-md-4"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" id="set-{{ $key }}" class="form-check-input" @checked($v === '1')><label for="set-{{ $key }}" class="form-check-label">{{ $label }}</label></div>
        @else
            <label class="form-label" for="set-{{ $key }}">{{ $label }}</label>
            @switch ($type)
                @case('textarea')<textarea name="{{ $key }}" id="set-{{ $key }}" rows="3" class="form-control">{{ $v }}</textarea>@break
                @case('select')<select name="{{ $key }}" id="set-{{ $key }}" class="form-select">@foreach ($f[4] ?? [] as $ok => $ol)<option value="{{ $ok }}" @selected((string) $v === (string) $ok)>{{ $ol }}</option>@endforeach</select>@break
                @case('bill_item')<select name="{{ $key }}" id="set-{{ $key }}" class="form-select"><option value="">None (free)</option>@foreach (\App\Models\BillItem::orderBy('name')->pluck('name', 'id') as $ok => $ol)<option value="{{ $ok }}" @selected((string) $v === (string) $ok)>{{ $ol }}</option>@endforeach</select>@break
                @case('color')<input type="color" name="{{ $key }}" id="set-{{ $key }}" value="{{ $v ?: '#405189' }}" class="form-control form-control-color">@break
                @case('secret')<input type="password" name="{{ $key }}" id="set-{{ $key }}" class="form-control" autocomplete="new-password" placeholder="{{ ($values[$key] ?? null) ? 'Saved. Leave empty to keep it.' : '' }}">@break
                @case('number')<input type="number" step="any" min="0" name="{{ $key }}" id="set-{{ $key }}" value="{{ $v }}" class="form-control">@break
                @default<input name="{{ $key }}" id="set-{{ $key }}" value="{{ $v }}" class="form-control">
            @endswitch
        @endif
        @if ($help)<div class="form-text">{{ $help }}</div>@endif
    </div>
@endforeach
</div>
