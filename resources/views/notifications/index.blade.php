@extends('layouts.master')

@section('content')
@php $icon = ['success' => 'ri-checkbox-circle-line text-success', 'danger' => 'ri-error-warning-line text-danger', 'warning' => 'ri-alert-line text-warning', 'info' => 'ri-information-line text-info']; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Notifications', 'icon' => 'ri-notification-3-line'])
    @include('academics.partials.alerts')
    <div class="d-flex gap-2 mb-3"><form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-light">Mark all as read</button></form>
        <a href="{{ route('notifications.preferences') }}" class="btn btn-sm btn-light">Text and WhatsApp settings</a></div>
    <div class="card"><ul class="list-group list-group-flush">
        @forelse ($items as $n)
            <a href="{{ route('notifications.open', $n->id) }}" class="list-group-item list-group-item-action d-flex gap-3 {{ $n->read_at ? '' : 'bg-primary-subtle bg-opacity-25' }}">
                <i class="{{ $icon[$n->data['level'] ?? 'info'] ?? $icon['info'] }} fs-20"></i>
                <div class="flex-grow-1"><div class="fw-medium">{{ $n->data['title'] ?? 'Notice' }}</div><div class="fs-13 text-body">{{ $n->data['body'] ?? '' }}</div><div class="text-muted fs-12">{{ $n->created_at->format('j M Y H:i') }}</div></div>
            </a>
        @empty
            <li class="list-group-item text-muted text-center py-4">No notifications yet.</li>
        @endforelse
    </ul></div>
    {{ $items->links() }}
</div></div></div>
@endsection
