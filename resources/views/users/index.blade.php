@extends('layouts.master')

@section('content')
@php
    $canCreate = auth()->user()->can('Create user');
    $canUpdate = auth()->user()->can('Update user');
    $canDelete = auth()->user()->can('Delete user');
    $hasOld    = old('_form_mode') !== null;
    $oldRoles  = (array) old('roles', []);
@endphp

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0"><i class="ri-team-line me-1 align-middle"></i>Users</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">Users</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <div class="card">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <form method="GET" class="d-flex flex-wrap gap-2">
                        <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:240px" placeholder="Search name, email, phone…">
                        <select name="role" class="form-select" style="width:auto" onchange="this.form.submit()">
                            <option value="">All roles</option>
                            @foreach ($allRoles as $r) <option value="{{ $r }}" @selected(request('role') === $r)>{{ $r }}</option> @endforeach
                            <option value="__none" @selected(request('role') === '__none')>No role</option>
                        </select>
                        <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                        @if (request()->query()) <a href="{{ route('users.index') }}" class="btn btn-light">Clear</a> @endif
                    </form>
                    @if ($canCreate)
                        <button type="button" class="btn btn-primary" id="btn-add"><i class="ri-user-add-line me-1"></i>Add user</button>
                    @endif
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>User</th><th>Phone</th><th>Roles</th><th>Last login</th><th>Status</th><th class="text-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $u)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold" style="width:36px;height:36px;">{{ $u->initials }}</span>
                                            <div>
                                                <a href="{{ route('users.show', $u) }}" class="fw-semibold text-reset">{{ $u->name }}</a>
                                                <div class="text-muted fs-12">{{ $u->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $u->phone_number ?: '—' }}</td>
                                    <td>
                                        @forelse ($u->roles as $role)
                                            <span class="{{ $role->badge ?: 'badge bg-secondary' }}">{{ $role->name }}</span>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                    <td class="text-muted fs-13">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td>
                                        <span class="badge {{ $u->is_disabled ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">{{ $u->is_disabled ? 'Disabled' : 'Active' }}</span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if ($canUpdate)
                                            <button type="button" class="btn btn-sm btn-soft-primary btn-edit" title="Edit"
                                                    data-url="{{ route('users.update', $u) }}"
                                                    data-record="{{ json_encode(['name' => $u->name, 'email' => $u->email, 'phone_number' => $u->phone_number, 'roles' => $u->roles->pluck('name')]) }}"><i class="ri-pencil-line"></i></button>
                                            <form method="POST" action="{{ route('users.reset-password', $u) }}" class="d-inline" onsubmit="return confirm('Generate a new temporary password for {{ addslashes($u->name) }}?')">
                                                @csrf <button class="btn btn-sm btn-soft-warning" title="Reset password"><i class="ri-key-2-line"></i></button>
                                            </form>
                                            @if (! $u->is(auth()->user()))
                                                <form method="POST" action="{{ route('users.toggle-status', $u) }}" class="d-inline">
                                                    @csrf <button class="btn btn-sm btn-soft-secondary" title="{{ $u->is_disabled ? 'Enable' : 'Disable' }}"><i class="{{ $u->is_disabled ? 'ri-user-follow-line' : 'ri-user-forbid-line' }}"></i></button>
                                                </form>
                                            @endif
                                        @endif
                                        @if ($canDelete && ! $u->is(auth()->user()))
                                            <form method="POST" action="{{ route('users.destroy', $u) }}" class="d-inline" onsubmit="return confirm('Delete {{ addslashes($u->name) }}? This cannot be undone.')">
                                                @csrf @method('DELETE') <button class="btn btn-sm btn-soft-danger" title="Delete"><i class="ri-delete-bin-line"></i></button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-5">No users found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($users->hasPages()) <div class="card-footer">{{ $users->links() }}</div> @endif
            </div>
        </div>
    </div>
</div>

@if ($canCreate || $canUpdate)
<div class="modal fade" id="user-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="{{ route('users.store') }}" class="modal-content" id="user-form" autocomplete="off">
            @csrf
            <input type="hidden" name="_method" value="POST" id="user-method">
            <input type="hidden" name="_form_mode" value="{{ old('_form_mode', 'create') }}" id="user-mode">
            <input type="hidden" name="_form_url" value="{{ old('_form_url') }}" id="user-url">
            <div class="modal-header bg-light">
                <h5 class="modal-title" id="user-title">Add user</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" required>
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone_number" class="form-control @error('phone_number') is-invalid @enderror" value="{{ old('phone_number') }}">
                        @error('phone_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6" data-only="create"></div>
                    <div class="col-md-6" data-only="create">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password">
                        <div class="form-text">Leave blank to generate a temporary password.</div>
                        @error('password') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6" data-only="create">
                        <label class="form-label">Confirm password</label>
                        <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Roles</label>
                        <div class="row g-2">
                            @foreach ($roles as $r)
                                <div class="col-md-4 col-6">
                                    <div class="form-check">
                                        <input class="form-check-input role-box" type="checkbox" name="roles[]" value="{{ $r }}" id="role-{{ Str::slug($r) }}" @checked(in_array($r, $oldRoles))>
                                        <label class="form-check-label" for="role-{{ Str::slug($r) }}">{{ $r }}</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @error('roles.*') <div class="text-danger fs-12 mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const form = document.getElementById('user-form');
    const modal = new bootstrap.Modal(document.getElementById('user-modal'));
    const storeUrl = @json(route('users.store'));

    function setMode(mode, url) {
        const editing = mode === 'edit';
        form.action = editing ? url : storeUrl;
        document.getElementById('user-method').value = editing ? 'PUT' : 'POST';
        document.getElementById('user-mode').value = mode;
        document.getElementById('user-url').value = url || '';
        document.getElementById('user-title').textContent = editing ? 'Edit user' : 'Add user';
        form.querySelectorAll('[data-only="create"]').forEach(el => el.style.display = editing ? 'none' : '');
        form.querySelectorAll('[data-only="create"] input').forEach(el => el.disabled = editing);
    }

    function reset() {
        form.querySelectorAll('input[type=text],input[type=email],input[type=password]').forEach(el => { el.value = ''; el.classList.remove('is-invalid'); });
        form.querySelectorAll('.role-box').forEach(el => el.checked = false);
    }

    document.getElementById('btn-add')?.addEventListener('click', () => { reset(); setMode('create'); modal.show(); });

    document.querySelectorAll('.btn-edit').forEach(btn => btn.addEventListener('click', () => {
        const rec = JSON.parse(btn.dataset.record);
        reset(); setMode('edit', btn.dataset.url);
        form.querySelector('[name=name]').value = rec.name || '';
        form.querySelector('[name=email]').value = rec.email || '';
        form.querySelector('[name=phone_number]').value = rec.phone_number || '';
        form.querySelectorAll('.role-box').forEach(el => el.checked = (rec.roles || []).includes(el.value));
        modal.show();
    }));

    @if ($errors->any() && $hasOld)
        setMode(@json(old('_form_mode')), @json(old('_form_url')));
        modal.show();
    @else
        setMode('create');
    @endif
})();
</script>
@endpush
@endif
@endsection
