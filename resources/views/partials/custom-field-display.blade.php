{{-- Read-only card of the extra answers on a profile. Vars: $cfTarget, $cfSubject --}}
@php $cfRows = \App\Support\CustomFields::display($cfTarget, $cfSubject); @endphp
@if (count($cfRows))
<div class="card">
    <div class="card-header"><h6 class="card-title mb-0">Additional information</h6></div>
    <ul class="list-group list-group-flush">
        @foreach ($cfRows as $r)
            <li class="list-group-item d-flex justify-content-between gap-3">
                <span class="text-muted">{{ $r['label'] }}</span>
                <span class="text-end">
                    @if ($r['file'] && $r['file']->file_path)
                        <a href="{{ route($cfFileRoute ?? 'custom-fields.download', $r['file']->id) }}" target="_blank"><i class="ri-attachment-2"></i> {{ $r['text'] }}</a>
                    @else
                        {{ $r['text'] ?? '—' }}
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</div>
@endif
