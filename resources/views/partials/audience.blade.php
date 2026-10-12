{{-- Who something is for. Pass $audience (App\Data\Campus\Audience or null), optional $prefix (default "audience"),
     $who (allowed "who" options; default all) and $genders (show the gender filter; default true). --}}
@php
    $prefix ??= 'audience';
    $a = $audience ?? new \App\Data\Campus\Audience();
    $whoOptions = array_intersect_key(\App\Data\Campus\Audience::WHO, array_flip($who ?? array_keys(\App\Data\Campus\Audience::WHO)));
    $showGenders = $genders ?? true;
    $sel = fn ($list, $v) => in_array($v, old(str_replace(['[', ']'], ['.', ''], $prefix) . '.' . $list, $a->{$list}));
@endphp
<div class="row g-2">
    <div class="col-md-4"><label class="form-label">Open to</label>
        <select name="{{ $prefix }}[who]" class="form-select" @if (count($whoOptions) === 1) readonly @endif>
            @foreach ($whoOptions as $k => $l)<option value="{{ $k }}" @selected(old("{$prefix}.who", $a->who) === $k)>{{ $l }}</option>@endforeach
        </select>
        <div class="form-text">Leave the lists on the right empty to include everyone.</div>
    </div>
    <div class="col-md-4"><label class="form-label">Faculties</label>
        <select name="{{ $prefix }}[faculties][]" multiple size="4" class="form-select">@foreach (\App\Models\Faculty::orderBy('name')->pluck('name', 'id') as $id => $n)<option value="{{ $id }}" @selected($sel('faculties', $id))>{{ $n }}</option>@endforeach</select>
    </div>
    <div class="col-md-4"><label class="form-label">Departments</label>
        <select name="{{ $prefix }}[departments][]" multiple size="4" class="form-select">@foreach (\App\Models\Department::orderBy('name')->pluck('name', 'id') as $id => $n)<option value="{{ $id }}" @selected($sel('departments', $id))>{{ $n }}</option>@endforeach</select>
    </div>
    <div class="col-md-5"><label class="form-label">Programmes <span class="text-muted">(students only)</span></label>
        <select name="{{ $prefix }}[programmes][]" multiple size="4" class="form-select">@foreach (\App\Models\Programme::orderBy('name')->pluck('name', 'id') as $id => $n)<option value="{{ $id }}" @selected($sel('programmes', $id))>{{ $n }}</option>@endforeach</select>
    </div>
    <div class="col-md-3"><label class="form-label">Levels <span class="text-muted">(students only)</span></label>
        <select name="{{ $prefix }}[levels][]" multiple size="4" class="form-select">@foreach (\App\Support\Levels::options() as $l => $label)<option value="{{ $l }}" @selected($sel('levels', $l))>{{ $label }}</option>@endforeach</select>
    </div>
    @if ($showGenders)
        <div class="col-md-4"><label class="form-label">Gender</label>
            <select name="{{ $prefix }}[genders][]" multiple size="4" class="form-select">@foreach (\App\Support\Lookups::options('gender') as $k => $l)<option value="{{ $k }}" @selected($sel('genders', $k))>{{ $l }}</option>@endforeach</select>
        </div>
    @endif
</div>
