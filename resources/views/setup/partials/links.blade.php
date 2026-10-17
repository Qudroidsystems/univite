{{-- Links from a setup page to copying it to another session and to saving / restoring it as a snapshot. --}}
@php $sessionId = is_numeric($session ?? null) ? (int) $session : null; @endphp
@if (($copy ?? []) || ($sections ?? []))
    <div class="d-flex flex-wrap gap-2">
        @if ($copy ?? [])
            @can('Copy session setup')<a href="{{ route('sessions.clone', array_filter(['source' => $sessionId, 'categories' => $copy])) }}" class="btn btn-sm btn-soft-primary"><i class="ri-file-copy-2-line me-1"></i>Copy to another session</a>@endcan
        @endif
        @if ($sections ?? [])
            @can('Manage setup snapshots')<a href="{{ route('setup.snapshots.index', array_filter(['session' => $sessionId, 'sections' => $sections])) }}" class="btn btn-sm btn-soft-secondary" title="Save this setup now, or put back an earlier one"><i class="ri-camera-line me-1"></i>Snapshot / restore</a>@endcan
        @endif
    </div>
@endif
