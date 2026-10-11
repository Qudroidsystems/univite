@extends('layouts.master')

@section('content')
@php $badge = ['sent' => 'bg-success-subtle text-success', 'logged' => 'bg-secondary-subtle text-secondary', 'failed' => 'bg-danger-subtle text-danger', 'queued' => 'bg-warning-subtle text-warning']; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Message log', 'icon' => 'ri-chat-history-line', 'subtitle' => 'Every text and WhatsApp message the portal has sent or tried to send.'])
    @include('academics.partials.alerts')
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        @foreach (['sent' => 'Sent', 'logged' => 'Logged only (no provider)', 'failed' => 'Failed'] as $k => $l)<span class="badge {{ $badge[$k] }} fs-12">{{ $l }}: {{ $counts[$k] ?? 0 }}</span>@endforeach
        <span class="text-muted fs-12">last 30 days</span>
        @if ($counts['failed'] ?? 0)<form method="POST" action="{{ route('messaging.retry') }}" class="ms-auto">@csrf<button class="btn btn-sm btn-soft-danger">Retry failed messages</button></form>@endif
    </div>
    <div class="card"><div class="card-header"><form method="GET" class="d-flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" style="width:200px" placeholder="Number or text">
        <select name="channel" class="form-select form-select-sm" style="width:auto"><option value="">SMS and WhatsApp</option><option value="sms" @selected(request('channel') === 'sms')>SMS</option><option value="whatsapp" @selected(request('channel') === 'whatsapp')>WhatsApp</option></select>
        <select name="status" class="form-select form-select-sm" style="width:auto"><option value="">Any status</option>@foreach (['sent', 'logged', 'failed'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
        <select name="topic" class="form-select form-select-sm" style="width:auto"><option value="">Any topic</option>@foreach ($topics as $k => $l)<option value="{{ $k }}" @selected(request('topic') === $k)>{{ $l }}</option>@endforeach</select>
        <button class="btn btn-sm btn-primary">Filter</button></form></div>
        <div class="table-responsive"><table class="table align-middle mb-0 fs-13">
            <thead class="table-light"><tr><th>When</th><th>To</th><th>Channel</th><th>Message</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($messages as $m)
                <tr><td class="text-nowrap">{{ $m->created_at->format('j M H:i') }}</td><td>{{ $m->user?->name }}<div class="text-muted">{{ $m->recipient }}</div></td><td>{{ $m->channel === 'sms' ? 'SMS' : 'WhatsApp' }}</td>
                    <td style="max-width:460px">{{ Str::limit($m->body, 200) }}</td>
                    <td><span class="badge {{ $badge[$m->status] ?? 'bg-light' }}">{{ $m->status }}</span>@if ($m->error)<div class="text-danger fs-12">{{ Str::limit($m->error, 120) }}</div>@endif @if ($m->attempts > 1)<div class="text-muted fs-12">{{ $m->attempts }} attempts</div>@endif</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No messages yet.</td></tr>
            @endforelse
            </tbody></table></div>
        <div class="card-body">{{ $messages->links() }}</div></div>
</div></div></div>
@endsection
