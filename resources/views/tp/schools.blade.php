@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Teaching practice schools', 'icon' => 'ri-school-line', 'back' => [route('tp.exercises.index'), 'Teaching practice']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @can('Set up teaching practice')
        @php $s = $editing ?? new \App\Models\TpSchool(['category' => 'secondary', 'default_capacity' => 10, 'is_active' => true]); @endphp
        <form method="POST" action="{{ $s->exists ? route('tp.schools.update', $s) : route('tp.schools.store') }}" class="card">@csrf @if ($s->exists) @method('PUT') @endif
            <div class="card-header"><h6 class="card-title mb-0">{{ $s->exists ? 'Edit ' . $s->name : 'Add a school' }}</h6></div>
            <div class="card-body row g-2">
                <div class="col-md-4"><input name="name" value="{{ old('name', $s->name) }}" class="form-control" placeholder="School name" required></div>
                <div class="col-md-2"><select name="category" class="form-select">@foreach (\App\Models\TpSchool::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $s->category) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-3"><input name="town" value="{{ old('town', $s->town) }}" class="form-control" placeholder="Town"></div>
                <div class="col-md-3"><input name="lga" value="{{ old('lga', $s->lga) }}" class="form-control" placeholder="LGA"></div>
                <div class="col-md-4"><input name="address" value="{{ old('address', $s->address) }}" class="form-control" placeholder="Address"></div>
                <div class="col-md-2"><input name="state" value="{{ old('state', $s->state) }}" class="form-control" placeholder="State"></div>
                <div class="col-md-2"><input name="phone" value="{{ old('phone', $s->phone) }}" class="form-control" placeholder="Phone"></div>
                <div class="col-md-2"><input name="head_name" value="{{ old('head_name', $s->head_name) }}" class="form-control" placeholder="Principal / head"></div>
                <div class="col-md-1"><input name="default_capacity" type="number" min="1" value="{{ old('default_capacity', $s->default_capacity) }}" class="form-control" title="Usual number of student teachers"></div>
                <div class="col-md-1 d-flex align-items-center"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="form-check-input" title="In use" @checked(old('is_active', $s->is_active))></div>
            </div>
            <div class="card-footer text-end">@if ($s->exists)<a href="{{ route('tp.schools.index') }}" class="btn btn-light">Cancel</a>@endif <button class="btn btn-primary">Save</button></div>
        </form>
    @endcan
    <div class="card">
        <div class="card-header"><form method="GET" class="d-flex gap-2" style="max-width:420px"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name, town or LGA"><button class="btn btn-sm btn-light">Search</button></form></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>School</th><th>Where</th><th>Head</th><th class="text-center">Usual places</th><th></th></tr></thead><tbody>
            @forelse ($schools as $sc)
                <tr class="{{ $sc->is_active ? '' : 'text-muted' }}"><td class="fw-semibold">{{ $sc->name }}<div class="fs-12 text-muted">{{ \App\Models\TpSchool::CATEGORIES[$sc->category] }}</div></td>
                    <td class="fs-13">{{ $sc->address }}<div>{{ $sc->place() }}</div></td><td class="fs-13">{{ $sc->head_name }}<div>{{ $sc->phone }}</div></td>
                    <td class="text-center">{{ $sc->default_capacity }}</td>
                    <td class="text-end">@can('Set up teaching practice')<a href="{{ route('tp.schools.index', ['edit' => $sc->id]) }}" class="btn btn-sm btn-light">Edit</a>
                        <form method="POST" action="{{ route('tp.schools.destroy', $sc) }}" class="d-inline" onsubmit="return confirm('Remove this school?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>@endcan</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-5">No schools yet.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($schools->hasPages())<div class="card-footer">{{ $schools->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
