@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">{{ $user->name }}</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                                <li class="breadcrumb-item active">{{ $user->name }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold fs-3 mb-3" style="width:80px;height:80px;">{{ $user->initials }}</span>
                            <h5 class="mb-1">{{ $user->name }}</h5>
                            <p class="text-muted mb-2">{{ $user->email }}</p>
                            <span class="badge {{ $user->is_disabled ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">{{ $user->is_disabled ? 'Disabled' : 'Active' }}</span>
                        </div>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Phone</span><span>{{ $user->phone_number ?: '—' }}</span></li>
                            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Last login</span><span>{{ $user->last_login_at?->format('d M Y, H:i') ?? 'Never' }}</span></li>
                            <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Created</span><span>{{ $user->created_at->format('d M Y') }}</span></li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Roles and permissions</h6></div>
                        <div class="card-body">
                            @forelse ($user->roles as $role)
                                <div class="mb-3">
                                    <a href="{{ route('roles.show', $role->id) }}" class="{{ $role->badge ?: 'badge bg-secondary' }} fs-12">{{ $role->name }}</a>
                                    <div class="mt-2 d-flex flex-wrap gap-1">
                                        @foreach ($role->permissions->sortBy('name') as $permission)
                                            <span class="badge bg-light text-body border fw-normal">{{ $permission->name }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                <p class="text-muted mb-0">This user has no role yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
