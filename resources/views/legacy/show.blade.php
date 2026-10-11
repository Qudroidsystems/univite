@extends('layouts.master')

@section('content')
@php $open = in_array($import->status, ['draft', 'checked'], true); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $import->file_name, 'icon' => 'ri-file-excel-2-line', 'subtitle' => \App\Models\LegacyImport::KINDS[$import->kind] . ' · ' . number_format($import->rows) . ' row(s) · ' . \App\Models\LegacyImport::STATUSES[$import->status], 'back' => [route('legacy.index'), 'Legacy results']])
    @include('academics.partials.alerts')

    @if ($import->status === 'imported')
        <div class="alert alert-success d-flex flex-wrap gap-2 align-items-center">
            <span class="me-auto">Imported {{ number_format($import->imported) }} {{ $import->kind === 'balances' ? 'previous total(s)' : 'result(s)' }} on {{ $import->imported_at->format('j M Y H:i') }}.</span>
            @if ($import->operation)<a href="{{ route('operations.show', $import->operation) }}" class="btn btn-sm btn-light">Operation {{ $import->operation->operation_id }} (undo from there)</a>@endif
        </div>
    @elseif ($import->status === 'undone')
        <div class="alert alert-warning">This import was undone. Upload the file again to import it again.</div>
    @endif

    <div class="row">
        <div class="col-xl-5">
            <form method="POST" action="{{ route('legacy.check', $import) }}" class="card">@csrf
                <div class="card-header"><h6 class="card-title mb-1">Match the columns</h6><div class="text-muted fs-12">Guessed from the headings. Fields marked * must be matched.</div></div>
                <div class="card-body">
                    @foreach ($fields as $f)
                        <div class="row g-2 align-items-center mb-2">
                            <label class="col-5 col-form-label fs-13">{{ $f->label }}@if ($f->required) <span class="text-danger">*</span>@endif</label>
                            <div class="col-7"><select name="map[{{ $f->key }}]" class="form-select form-select-sm" @disabled(! $open)>
                                <option value="">Not in this file</option>
                                @foreach ($import->headers as $i => $h)<option value="{{ $i }}" @selected(($mapping->columns[$f->key] ?? null) === $i)>{{ $h !== '' ? $h : 'Column ' . ($i + 1) }}</option>@endforeach
                            </select>@if ($f->help)<div class="form-text fs-11">{{ $f->help }}</div>@endif</div>
                        </div>
                    @endforeach
                    <hr>
                    @foreach (\App\Data\Legacy\ImportOptions::LABELS as $k => $label)
                        @continue($k === 'create_courses' && $import->kind === 'balances')
                        <div class="form-check mb-1"><input type="checkbox" class="form-check-input" name="options[{{ $k }}]" value="1" id="o{{ $k }}" @checked($options->toArray()[$k]) @disabled(! $open)><label class="form-check-label fs-13" for="o{{ $k }}">{{ $label }}</label></div>
                    @endforeach
                </div>
                @if ($open)
                    <div class="card-footer d-flex gap-2"><button class="btn btn-primary">Check the file</button><span class="text-muted fs-12 align-self-center">Nothing is saved yet.</span></div>
                @endif
            </form>
            @if ($open)
                <form method="POST" action="{{ route('legacy.destroy', $import) }}" onsubmit="return confirm('Remove this upload?')">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger px-0">Remove this upload</button></form>
            @endif
        </div>

        <div class="col-xl-7">
            @if ($report)
                <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">{{ $import->status === 'imported' ? 'What was imported' : 'What the check found' }}</h6><span class="text-muted fs-12">{{ $report->checkedAt }}</span></div>
                    <div class="card-body">
                        <div class="row g-3 mb-3 text-center">
                            <div class="col"><div class="fs-3 fw-semibold text-success">{{ number_format($report->imported ?? $report->ok) }}</div><div class="text-muted fs-12">{{ $report->imported !== null ? 'imported' : 'ready to import' }}</div></div>
                            <div class="col"><div class="fs-3 fw-semibold">{{ number_format($report->students) }}</div><div class="text-muted fs-12">students</div></div>
                            <div class="col"><div class="fs-3 fw-semibold {{ $report->problemCount ? 'text-danger' : 'text-muted' }}">{{ number_format($report->problemCount) }}</div><div class="text-muted fs-12">left out</div></div>
                            <div class="col"><div class="fs-3 fw-semibold text-muted">{{ number_format($report->existing) }}</div><div class="text-muted fs-12">already in the portal</div></div>
                        </div>
                        @if ($report->newSessions)<div class="fs-13 mb-2"><strong>Sessions to add:</strong> {{ implode(', ', $report->newSessions) }}</div>@endif
                        @if ($report->newCourses)<div class="fs-13 mb-2"><strong>Courses to add ({{ count($report->newCourses) }}, added as inactive):</strong> {{ implode('; ', array_slice($report->newCourses, 0, 30)) }}{{ count($report->newCourses) > 30 ? ' …' : '' }}</div>@endif
                        @if ($report->sessions)<div class="fs-13 mb-2"><strong>Rows by session:</strong> {{ collect($report->sessions)->map(fn ($n, $s) => "{$s}: {$n}")->implode(' · ') }}</div>@endif
                        @if ($import->status === 'checked' && $report->ok)
                            <form method="POST" action="{{ route('legacy.import', $import) }}" class="mt-3" onsubmit="return confirm('Import {{ $report->ok }} row(s){{ $report->skipped() ? ' and leave out ' . $report->skipped() : '' }}?')">@csrf
                                <button class="btn btn-success"><i class="ri-upload-2-line me-1"></i>Import {{ number_format($report->ok) }} row(s)</button>
                                @if ($report->problemCount)<span class="text-muted fs-12 ms-2">The {{ $report->problemCount }} row(s) with problems are left out; fix them and upload them as a new file.</span>@endif
                            </form>
                        @endif
                    </div></div>

                @if ($report->preview)
                    <div class="card"><div class="card-header"><h6 class="card-title mb-0">First rows, as they will be saved</h6></div>
                        <div class="table-responsive"><table class="table table-sm mb-0 fs-12">
                            <thead class="table-light"><tr>@foreach (array_keys($report->preview[0]) as $k)<th>{{ ucfirst(str_replace('_', ' ', $k)) }}</th>@endforeach</tr></thead>
                            <tbody>@foreach ($report->preview as $p)<tr>@foreach ($p as $v)<td>{{ $v ?? '—' }}</td>@endforeach</tr>@endforeach</tbody>
                        </table></div></div>
                @endif

                @if ($report->problemCount || $report->warningCount)
                    <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Problems and warnings</h6><a href="{{ route('legacy.problems', $import) }}" class="btn btn-sm btn-light"><i class="ri-download-line me-1"></i>Download as CSV</a></div>
                        <div class="table-responsive" style="max-height:460px"><table class="table table-sm mb-0 fs-12">
                            <thead class="table-light"><tr><th>Row</th><th>Matric</th><th></th></tr></thead>
                            <tbody>
                            @foreach (array_slice($report->problems, 0, 200) as $p)<tr><td>{{ $p->row }}</td><td>{{ $p->matric }}</td><td class="text-danger">{{ $p->message }}</td></tr>@endforeach
                            @foreach (array_slice($report->warnings, 0, 100) as $w)<tr><td>{{ $w->row }}</td><td>{{ $w->matric }}</td><td class="text-warning">{{ $w->message }}</td></tr>@endforeach
                            </tbody></table></div>
                        @if ($report->problemCount > 200 || $report->warningCount > 100)<div class="card-body fs-12 text-muted">Showing the first ones; download the CSV for all of them.</div>@endif
                    </div>
                @endif
            @else
                <div class="card"><div class="card-body text-muted">Match the columns and press <strong>Check the file</strong>. Every row is checked and nothing is saved until you import.</div></div>
            @endif
        </div>
    </div>
</div></div></div>
@endsection
