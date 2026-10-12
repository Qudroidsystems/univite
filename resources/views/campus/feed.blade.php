@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Campus Feed', 'icon' => 'ri-megaphone-line', 'subtitle' => 'News and events that appear in the mobile app.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    @if ($audiences)
        <form method="POST" action="{{ route('campus.feed.store') }}" enctype="multipart/form-data" class="card" data-no-ajax>
            @csrf
            <div class="card-header"><h6 class="card-title mb-0">New post</h6></div>
            <div class="card-body row g-3">
                <div class="col-md-4"><label class="form-label">Who sees it</label>
                    <select name="audience" class="form-select" id="feed-audience">
                        @foreach ($audiences as $aud => $opts)<option value="{{ $aud }}">{{ \App\Models\FeedPost::AUDIENCES[$aud] }}</option>@endforeach
                    </select></div>
                @foreach (['faculty', 'department'] as $aud)
                    @if (isset($audiences[$aud]))
                        <div class="col-md-4" data-aud="{{ $aud }}"><label class="form-label">{{ ucfirst($aud) }}</label>
                            <select name="{{ $aud }}_id" class="form-select">@foreach ($audiences[$aud] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></div>
                    @endif
                @endforeach
                <div class="col-12"><label class="form-label">Title</label><input name="title" class="form-control" maxlength="150" value="{{ old('title') }}"></div>
                <div class="col-12"><label class="form-label">Message</label><textarea name="body" class="form-control" rows="4" required maxlength="5000">{{ old('body') }}</textarea></div>
                <div class="col-md-6"><label class="form-label">Picture (optional)</label><input type="file" name="image" accept="image/*" class="form-control"></div>
                <div class="col-md-6 d-flex gap-4 align-items-end flex-wrap">
                    <div class="form-check form-switch"><input type="hidden" name="allow_comments" value="0"><input class="form-check-input" type="checkbox" name="allow_comments" value="1" id="ac" checked><label for="ac" class="form-check-label">Allow comments</label></div>
                    @can('Post to the campus feed')<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pinned" value="1" id="pin"><label for="pin" class="form-check-label">Pin to the top</label></div>@endcan
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_event" value="1" id="ev"><label for="ev" class="form-check-label">This is an event</label></div>
                </div>
                <div class="col-md-4" data-event><label class="form-label">Starts</label><input type="datetime-local" name="starts_at" class="form-control"></div>
                <div class="col-md-4" data-event><label class="form-label">Ends</label><input type="datetime-local" name="ends_at" class="form-control"></div>
                <div class="col-md-4" data-event><label class="form-label">Venue</label><input name="venue" class="form-control" maxlength="150"></div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary"><i class="ri-send-plane-line me-1"></i>Post</button></div>
        </form>
    @endif

    <div class="card">
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Post</th><th>For</th><th>By</th><th>When</th><th></th></tr></thead>
            <tbody>
            @forelse ($posts as $p)
                <tr>
                    <td style="max-width:420px">@if ($p->pinned)<i class="ri-pushpin-fill text-warning me-1"></i>@endif<span class="fw-semibold">{{ $p->title ?: \Illuminate\Support\Str::limit($p->body, 60) }}</span>
                        @if ($p->is_event)<span class="badge bg-info-subtle text-info ms-1">Event {{ $p->starts_at?->format('j M, H:i') }}</span>@endif
                        @if ($p->status === 'hidden')<span class="badge bg-secondary-subtle text-secondary ms-1">Hidden</span>@endif
                        <div class="text-muted fs-12">{{ \Illuminate\Support\Str::limit($p->body, 140) }} · {{ $p->comments_count }} comment(s)</div></td>
                    <td>{{ $p->audienceLabel() }}</td>
                    <td>{{ $p->user?->name ?? 'The school' }}</td>
                    <td class="text-nowrap">{{ $p->created_at->format('j M Y, H:i') }}</td>
                    <td class="text-end text-nowrap">
                        <form method="POST" action="{{ route('campus.feed.moderate', $p->id) }}" class="d-inline">@csrf
                            @can('Post to the campus feed')<button name="action" value="{{ $p->pinned ? 'unpin' : 'pin' }}" class="btn btn-sm btn-light">{{ $p->pinned ? 'Unpin' : 'Pin' }}</button>@endcan
                            <button name="action" value="{{ $p->status === 'hidden' ? 'show' : 'hide' }}" class="btn btn-sm btn-soft-warning">{{ $p->status === 'hidden' ? 'Show' : 'Hide' }}</button>
                            <button name="action" value="delete" class="btn btn-sm btn-soft-danger" data-confirm="Delete this post?">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No posts yet.</td></tr>
            @endforelse
            </tbody></table></div>
        <div class="card-footer">{{ $posts->links() }}</div>
    </div>
</div></div></div>
@endsection

@push('scripts')
<script>
(function () {
    var a = document.getElementById('feed-audience'), ev = document.getElementById('ev');
    function sync() {
        document.querySelectorAll('[data-aud]').forEach(function (el) { el.style.display = a && el.getAttribute('data-aud') === a.value ? '' : 'none'; });
        document.querySelectorAll('[data-event]').forEach(function (el) { el.style.display = ev && ev.checked ? '' : 'none'; });
    }
    if (a) a.addEventListener('change', sync);
    if (ev) ev.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
