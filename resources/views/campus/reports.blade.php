@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Reports', 'icon' => 'ri-flag-line', 'subtitle' => 'What people reported in the mobile app. Removing hides a listing or post, or deletes a message or comment.'])
    @include('academics.partials.alerts')
    <ul class="nav nav-pills mb-3">
        @foreach (['open' => 'Open', 'actioned' => 'Removed', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $l)
            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ route('campus.reports', ['status' => $k]) }}">{{ $l }}@if ($k === 'open' && $open)<span class="badge bg-danger ms-1">{{ $open }}</span>@endif</a></li>
        @endforeach
    </ul>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>What</th><th>Reason</th><th>Reported by</th><th>When</th><th></th></tr></thead>
        <tbody>
        @forelse ($reports as $r)
            @php $t = $r->target(); @endphp
            <tr>
                <td style="max-width:420px"><span class="badge bg-light text-body">{{ ucfirst($r->reportable_type) }}</span>
                    @if (! $t) <span class="text-muted">No longer there</span>
                    @elseif ($t instanceof \App\Models\ChatMessage) “{{ \Illuminate\Support\Str::limit($t->body ?? $t->attachment_name, 140) }}” <span class="text-muted fs-12">by {{ $t->user?->name }}</span>
                    @elseif ($t instanceof \App\Models\MarketListing) {{ $t->title }} <span class="text-muted fs-12">by {{ $t->user?->name }} ({{ $t->status }})</span>
                    @elseif ($t instanceof \App\Models\FeedPost) {{ $t->title ?: \Illuminate\Support\Str::limit($t->body, 100) }} <span class="text-muted fs-12">by {{ $t->user?->name }}</span>
                    @elseif ($t instanceof \App\Models\FeedComment) “{{ \Illuminate\Support\Str::limit($t->body, 140) }}” <span class="text-muted fs-12">by {{ $t->user?->name }}</span>
                    @elseif ($t instanceof \App\Models\User) {{ $t->name }} <span class="text-muted fs-12">{{ $t->email }}</span>
                    @endif
                    @if ($r->details)<div class="fs-12 text-muted">{{ $r->details }}</div>@endif</td>
                <td>{{ \App\Models\ContentReport::REASONS[$r->reason] ?? $r->reason }}</td>
                <td>{{ $r->reporter?->name }}</td>
                <td class="text-nowrap">{{ $r->created_at->format('j M Y, H:i') }}</td>
                <td class="text-end" style="min-width:260px">
                    @if ($r->status === 'open')
                        <form method="POST" action="{{ route('campus.reports.handle', $r->id) }}" class="d-flex gap-1">@csrf
                            <input name="outcome" class="form-control form-control-sm" placeholder="Note (optional)">
                            <button name="action" value="remove" class="btn btn-sm btn-soft-danger" @if ($r->reportable_type === 'user') disabled title="Suspend the account under Users instead" @endif>Remove</button>
                            <button name="action" value="dismiss" class="btn btn-sm btn-light">Dismiss</button>
                        </form>
                    @else
                        <span class="fs-12 text-muted">{{ ucfirst($r->status) }} by {{ $r->handler?->name }}{{ $r->outcome ? ': ' . $r->outcome : '' }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No reports.</td></tr>
        @endforelse
        </tbody></table></div>
        <div class="card-footer">{{ $reports->links() }}</div></div>
</div></div></div>
@endsection
