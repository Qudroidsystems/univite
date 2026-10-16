{{-- Download a score sheet: detailed (every assessment) or totals only, as Excel or CSV. Needs $sheet; optional $label, $small. --}}
<div class="dropdown d-inline-block">
    <button class="btn {{ ($small ?? false) ? 'btn-sm' : '' }} btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="ri-file-excel-2-line me-1 text-success"></i>{{ $label ?? 'Download' }}</button>
    <ul class="dropdown-menu">
        @foreach (\App\Services\ScoreSheetFile::TYPES as $type => $name)
            <li><h6 class="dropdown-header">{{ $name }}</h6></li>
            <li><a class="dropdown-item" data-no-ajax href="{{ route('results.sheets.template', [$sheet->id, 'type' => $type]) }}"><i class="ri-file-excel-2-line me-1 text-success"></i>Excel</a></li>
            <li><a class="dropdown-item" data-no-ajax href="{{ route('results.sheets.template', [$sheet->id, 'type' => $type, 'format' => 'csv']) }}"><i class="ri-file-text-line me-1"></i>CSV</a></li>
        @endforeach
    </ul>
</div>
