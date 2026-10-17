@extends('layouts.master')

@section('content')
@php
    use App\Support\AcademicRules;
    $isUniBase = $type === 'university' && ! $term;
    $keys = $term ? AcademicRules::perTermKeys() : array_keys(AcademicRules::DEFS);
    $groups = collect($keys)->groupBy(fn ($k) => AcademicRules::group($k));
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Academic rules</h4></div>
            @include('academics.partials.alerts')
            <p class="text-muted">The university sets the defaults. A faculty, department or programme can override any rule, and the credit load can also be set for one level or semester. The most specific setting wins.</p>

            <form method="GET" class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div class="flex-grow-1" style="min-width:260px"><label class="form-label mb-1">Rules for</label>
                    <select class="form-select" onchange="var v=this.value.split(':');this.form.scope.value=v[0];this.form.id.value=v[1];">
                        @foreach ($options as $label => $opts)
                            <optgroup label="{{ $label }}">@foreach ($opts as $v => $n)<option value="{{ $v }}" @selected($v === "{$type}:{$id}")>{{ $n }}</option>@endforeach</optgroup>
                        @endforeach
                    </select>
                    <input type="hidden" name="scope" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                </div>
                <div><label class="form-label mb-1">Level</label><select name="level" class="form-select"><option value="">All levels</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected($level === $v)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Semester</label><select name="semester_type_id" data-lookup="semester-types" class="form-select"><option value="">All semesters</option>@foreach ($semesters as $v => $l)<option value="{{ $v }}" @selected($sem === $v)>{{ $l }}</option>@endforeach</select></div>
                <button class="btn btn-primary" type="submit">Show</button>
            </div></form>

            <form method="POST" action="{{ route('academics.rules.update') }}" class="card">
                @csrf @method('PUT')
                <input type="hidden" name="scope" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                <input type="hidden" name="level" value="{{ $level }}"><input type="hidden" name="semester_type_id" value="{{ $sem }}">
                <div class="card-header">
                    <h6 class="card-title mb-0">{{ $scopeName }}{{ $level ? ' · ' . ($levels[$level] ?? $level) : '' }}{{ $sem ? ' · ' . ($semesters[$sem] ?? '') : '' }}</h6>
                    <div class="text-muted fs-12">
                        @if ($isUniBase) These are the defaults every faculty, department and programme starts from.
                        @else Inherits from {{ collect($chain)->pluck(2)->slice(0, $term ? null : -1)->implode(' → ') }}{{ $term ? ' (all levels / semesters)' : '' }}. Leave a rule on "Inherit" to keep the inherited value.
                        @endif
                        @if ($term) Only the credit-load rules can differ by level or semester. @endif
                    </div>
                </div>
                <div class="card-body">
                    @foreach ($groups as $group => $gkeys)
                        <h6 class="mt-2 mb-2 text-uppercase fs-12 text-muted">{{ $group }}</h6>
                        @foreach ($gkeys as $key)
                            @php
                                $d = AcademicRules::DEFS[$key];
                                $has = array_key_exists($key, $own);
                                $cur = $has ? $own[$key] : null;
                                $val = old("rules.$key", $isUniBase ? (is_bool($cur) ? (int) $cur : $cur) : ($has ? (is_bool($cur) ? (int) $cur : $cur) : ''));
                                $inh = $inherited[$key];
                            @endphp
                            <div class="row g-2 align-items-center py-2 border-bottom">
                                <div class="col-md-6"><div class="fw-medium">{{ $d[1] }}</div><div class="text-muted fs-12">{{ $d[3] }}</div></div>
                                <div class="col-md-3 fs-13">
                                    @unless ($isUniBase)<span class="text-muted">Inherited:</span> {{ AcademicRules::display($key, $inh['value']) }}<div class="text-muted fs-12">from {{ $inh['from'] }}</div>@endunless
                                </div>
                                <div class="col-md-3">
                                    @if ($d[2] === 'bool')
                                        <select name="rules[{{ $key }}]" class="form-select form-select-sm" @disabled(! $canEdit)>
                                            @unless ($isUniBase)<option value="">Inherit</option>@endunless
                                            <option value="1" @selected((string) $val === '1')>Yes</option><option value="0" @selected((string) $val === '0')>No</option>
                                        </select>
                                    @elseif ($d[2] === 'choice')
                                        <select name="rules[{{ $key }}]" class="form-select form-select-sm" @disabled(! $canEdit)>
                                            @unless ($isUniBase)<option value="">Inherit</option>@endunless
                                            @foreach ($d[6] as $v => $l)<option value="{{ $v }}" @selected((string) $val === $v)>{{ $l }}</option>@endforeach
                                        </select>
                                    @else
                                        <input type="number" name="rules[{{ $key }}]" value="{{ $val }}" step="{{ $d[2] === 'decimal' ? '0.01' : '1' }}" min="0" class="form-control form-control-sm @error("rules.$key") is-invalid @enderror"
                                               placeholder="{{ $isUniBase ? 'Not set' : 'Inherit' }}" @disabled(! $canEdit)>
                                        @error("rules.$key")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                    @unless ($isUniBase)<div class="form-text">To switch a number rule off for this scope only, enter 0.</div>@endunless
                </div>
                @if ($canEdit)<div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save</button></div>@endif
            </form>

            @if ($programme && ! $term)
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Course advisers</h6>
                        <div class="text-muted fs-12">A course adviser approves the registration forms of this programme's students at their level, and sees them under "My Advisees". Anyone with "Approve course registrations" can still approve any form.</div></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <tbody>
                        @foreach ($programme->levels() as $lv)
                            <tr><th style="width:20%">{{ $programme->levelLabel($lv) }}</th>
                                <td>@forelse ($advisers->get($lv, collect()) as $a)
                                        <span class="badge bg-light text-body border me-1 fs-12">{{ $a->user->name }}
                                            @if ($canEdit)<form method="POST" action="{{ route('academics.rules.advisers.destroy', $a->id) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 ms-1 text-danger" title="Remove">&times;</button></form>@endif
                                        </span>
                                    @empty <span class="text-muted fs-13">None</span>@endforelse</td>
                                <td style="width:40%">@if ($canEdit)
                                    <form method="POST" action="{{ route('academics.rules.advisers.store', $programme->id) }}" class="d-flex gap-1">@csrf<input type="hidden" name="level" value="{{ $lv }}">
                                        <select name="user_id" class="form-select form-select-sm" required><option value="">Add adviser…</option>@foreach ($users as $uid => $n)<option value="{{ $uid }}">{{ $n }}</option>@endforeach</select>
                                        <button class="btn btn-sm btn-soft-primary" type="submit">Add</button></form>
                                @endif</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
            @endif

            @if ($department)
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Who manages {{ $department->name }}'s rules</h6>
                        <div class="text-muted fs-12">The head of department and these administrators can change the rules of this department and its programmes if their role has "Edit department academic rules". The faculty dean can too.</div></div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item">Head of department: {{ $department->hod?->name ?? 'not assigned' }}</li>
                        @foreach ($department->admins as $a)
                            <li class="list-group-item d-flex justify-content-between align-items-center">{{ $a->name }}
                                @can('Edit academic rules')
                                    <form method="POST" action="{{ route('academics.rules.admins', $department->id) }}">@csrf<input type="hidden" name="user_id" value="{{ $a->id }}"><input type="hidden" name="remove" value="1"><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                    @can('Edit academic rules')
                        <form method="POST" action="{{ route('academics.rules.admins', $department->id) }}" class="card-footer d-flex gap-2">
                            @csrf
                            <select name="user_id" class="form-select form-select-sm" required><option value="">Add an administrator…</option>@foreach ($users as $uid => $n)<option value="{{ $uid }}">{{ $n }}</option>@endforeach</select>
                            <button class="btn btn-sm btn-soft-primary text-nowrap" type="submit">Add</button>
                        </form>
                    @endcan
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">All overrides</h6></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Scope</th><th>Applies to</th><th>Rules changed</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($overrides as $o)
                        @php $r = $o['row']; @endphp
                        <tr>
                            <td><span class="text-muted fs-12">{{ $o['type'] }}</span><div>{{ $o['name'] }}</div></td>
                            <td>{{ $r->level ? ($levels[$r->level] ?? $r->level) : 'All levels' }} · {{ $r->semesterType?->name ?? 'All semesters' }}</td>
                            <td class="fs-13">@foreach ($r->rules as $k => $v)@if (isset(AcademicRules::DEFS[$k]))<div>{{ AcademicRules::DEFS[$k][1] }}: <strong>{{ AcademicRules::display($k, AcademicRules::cast($k, $v)) }}</strong></div>@endif @endforeach</td>
                            <td><x-last-change :record="$r" :among="collect($overrides)->pluck('row')" /></td>
                            <td class="text-end"><a class="btn btn-sm btn-soft-primary" href="{{ route('academics.rules.index', array_filter(['scope' => $r->scope_type, 'id' => $r->scope_id, 'level' => $r->level, 'semester_type_id' => $r->semester_type_id])) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">Every faculty, department and programme uses the university's rules.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
@endsection
