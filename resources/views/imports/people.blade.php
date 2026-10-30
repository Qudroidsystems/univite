@extends('layouts.master')

@section('content')
@php $what = $kind === 'students' ? 'students' : 'staff'; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Import ' . $what, 'icon' => 'ri-upload-cloud-2-line', 'subtitle' => 'Add ' . $what . ' from a spreadsheet, for example one exported from the old portal. Every row is checked before anything is saved, and an import can be undone.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card"><div class="card-body">
                <h6>1. Get the template</h6>
                <p class="text-muted fs-13">Fill in one row per person. Columns marked * are required. Codes (programme, department, entry mode) are the ones set up in the portal.</p>
                <a href="{{ route('imports.people.template', $kind) }}" class="btn btn-soft-primary btn-sm mb-3"><i class="ri-download-2-line me-1"></i>Download template (CSV)</a>
                <div class="fs-12 text-muted">@foreach ($columns as $c){{ $c[0] }}@if ($c[1])*@endif{{ $loop->last ? '' : ', ' }}@endforeach</div>
                <h6 class="mt-4">2. Upload it</h6>
                <form method="POST" action="{{ route('imports.people.store', $kind) }}" enctype="multipart/form-data" data-no-ajax>@csrf
                    <div class="input-group"><input type="file" name="file" class="form-control" accept=".csv,.xlsx" required><button class="btn btn-primary">Upload and check</button></div>
                    <div class="form-text">CSV or Excel (.xlsx), up to 10 MB. Nothing is saved until you press Import.</div>
                </form>
            </div></div>
        </div>
        <div class="col-lg-7">
            @forelse ($imports as $im)
                @php $r = $im->report ?? []; @endphp
                <div class="card"><div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div><span class="fw-semibold">{{ $im->file_name }}</span><div class="text-muted fs-12">{{ $im->rows }} rows · {{ $im->created_at->format('d M Y, H:i') }} · {{ $im->creator?->name }}</div></div>
                        <div>
                            @if ($im->status === 'checked')
                                <form method="POST" action="{{ route('imports.people.import', [$kind, $im]) }}" class="d-inline" onsubmit="return confirm('Add the {{ $r['ready'] ?? 0 }} ready rows now?')">@csrf<button class="btn btn-sm btn-success" @disabled(! ($r['ready'] ?? 0))>Import {{ $r['ready'] ?? 0 }}</button></form>
                            @elseif ($im->status === 'imported')
                                <span class="badge bg-success-subtle text-success">{{ $im->imported }} added</span>
                                <form method="POST" action="{{ route('imports.people.undo', [$kind, $im]) }}" class="d-inline" onsubmit="return confirm('Remove everyone this file added?')">@csrf<button class="btn btn-sm btn-link text-danger">Undo</button></form>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($im->status) }}</span>
                            @endif
                        </div>
                    </div>
                    @if ($r)
                        <div class="mt-2 fs-13"><span class="text-success">{{ $r['ready'] ?? 0 }} ready</span> · <span class="text-muted">{{ $r['existing'] ?? 0 }} already in the portal (left alone)</span> · <span class="text-danger">{{ count($r['problems'] ?? []) }} with problems</span></div>
                        @if (! empty($r['problems']))
                            <ul class="fs-12 text-danger mb-0 mt-1" style="max-height:160px;overflow:auto">@foreach ($r['problems'] as $row => $why)<li>Row {{ $row }}: {{ $why }}</li>@endforeach</ul>
                        @endif
                        @if ($im->status === 'checked' && ! empty($r['sample']))
                            <div class="fs-12 text-muted mt-2">First rows: {{ collect($r['sample'])->pluck('label')->implode('; ') }}</div>
                        @endif
                    @endif
                </div></div>
            @empty
                <div class="card"><div class="card-body text-muted">No files uploaded yet.</div></div>
            @endforelse
        </div>
    </div>
</div></div></div>
@endsection
