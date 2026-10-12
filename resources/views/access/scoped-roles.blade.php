@extends('layouts.master')

@section('content')
@php $edit = auth()->user()->can('Assign scoped roles'); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Scoped roles and heads', 'icon' => 'ri-organization-chart',
        'subtitle' => 'A scoped role gives someone a role for one faculty, department or unit only. Heads get theirs automatically when they are set as head.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="row">
        <div class="col-lg-4">
            @if ($edit)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Give a scoped role</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('scoped-roles.store') }}">@csrf
                        <div class="mb-2"><label class="form-label">Person</label><select name="user_id" class="form-select" required><option value="">Choose</option>@foreach ($users as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                        <div class="mb-2"><label class="form-label">Role</label><select name="role_id" class="form-select" required><option value="">Choose</option>@foreach ($roles as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                        <div class="mb-3"><label class="form-label">For</label><select name="scope" class="form-select" required><option value="">Choose</option>
                            @foreach ($scopes as $group => $opts)@if ($opts)<optgroup label="{{ $group }}">@foreach ($opts as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</optgroup>@endif @endforeach</select></div>
                        <button class="btn btn-primary">Give role</button>
                    </form>
                </div></div>
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Roles heads get</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('scoped-roles.settings') }}">@csrf @method('PUT')
                        @foreach ($headRoles as $type => $current)
                            <div class="mb-2"><label class="form-label">Head of a {{ $type }}</label><select name="{{ $type }}" class="form-select"><option value="">No role</option>@foreach ($roles as $name)<option value="{{ $name }}" @selected($current === $name)>{{ $name }}</option>@endforeach</select></div>
                        @endforeach
                        <button class="btn btn-soft-primary">Save and update heads</button>
                    </form>
                </div></div>
            @endif
            <div class="card"><div class="card-body fs-13">
                <div class="fw-semibold mb-1">What a scoped role can open</div>
                <p class="text-muted mb-1">Only these permissions work within a faculty, department or unit. Anything else in the role is ignored when it is held as a scoped role.</p>
                <ul class="mb-0">@foreach ($scopable as $perm)<li>{{ $perm }}</li>@endforeach</ul>
            </div></div>
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><form method="GET" class="d-flex gap-2"><select name="source" class="form-select" style="width:auto" onchange="this.form.submit()"><option value="">All</option><option value="head" @selected(request('source') === 'head')>Given to heads</option><option value="manual" @selected(request('source') === 'manual')>Given by hand</option></select></form></div>
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Person</th><th>Role</th><th>For</th><th>How</th><th></th></tr></thead><tbody>
                    @forelse ($rows as $r)
                        <tr><td>{{ $r->user->name }}</td><td>{{ $r->role->name }}</td><td>{{ $r->scopeName() }} <span class="text-muted fs-12">({{ $r->scope_type }})</span></td>
                            <td>@if ($r->source === 'head')<span class="badge bg-info-subtle text-info">Head</span>@else<span class="badge bg-light text-body">By hand</span>@endif</td>
                            <td class="text-end">@if ($edit && $r->source !== 'head')<form method="POST" action="{{ route('scoped-roles.destroy', $r) }}" data-confirm="Remove {{ $r->role->name }} for {{ $r->scopeName() }} from {{ $r->user->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger">Remove</button></form>@endif</td></tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-5">No scoped roles. Set heads on departments, faculties and units, or give one here.</td></tr>
                    @endforelse
                </tbody></table></div>
            </div>
        </div>
    </div>
</div></div></div>
@endsection
