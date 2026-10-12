@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Library catalogue', 'icon' => 'ri-book-2-line', 'subtitle' => number_format($totals['titles']) . ' titles · ' . number_format($totals['copies']) . ' copies · ' . number_format($totals['out']) . ' on loan'])
    @include('academics.partials.alerts')
    @if (session('import_errors'))
        <div class="alert alert-warning"><div class="fw-semibold">Some rows had problems:</div><ul class="mb-0 fs-13">@foreach (array_slice(session('import_errors'), 0, 30) as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
            <form method="GET" class="d-flex gap-2">
                <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Title, author, ISBN, call or accession number" style="min-width:280px">
                <select name="kind" class="form-select form-select-sm"><option value="">All kinds</option>@foreach (\App\Models\LibraryItem::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
                <button class="btn btn-sm btn-light">Search</button>
            </form>
            @can('Edit library catalogue')
                <div class="d-flex gap-2"><a href="{{ route('library.import') }}" class="btn btn-sm btn-light"><i class="ri-upload-2-line me-1"></i>Import</a><a href="{{ route('library.items.create') }}" class="btn btn-sm btn-primary"><i class="ri-add-line me-1"></i>Add a title</a></div>
            @endcan
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Title</th><th>Call number</th><th>Kind</th><th class="text-center">Copies</th><th class="text-center">On the shelf</th><th class="text-center">Waiting</th></tr></thead><tbody>
            @forelse ($items as $i)
                <tr>
                    <td><a href="{{ route('library.items.show', $i) }}" class="fw-semibold">{{ $i->title }}</a><div class="fs-12 text-muted">{{ $i->authors }}{{ $i->year ? ' · ' . $i->year : '' }}{{ $i->isbn ? ' · ISBN ' . $i->isbn : '' }}</div></td>
                    <td>{{ $i->call_number ?? '—' }}</td>
                    <td>{{ \App\Models\LibraryItem::KINDS[$i->kind] ?? $i->kind }}@if ($i->is_reference) <span class="badge bg-light text-body">Library use only</span>@endif</td>
                    <td class="text-center">{{ $i->copies_count }}</td>
                    <td class="text-center">{{ $i->available_count }}</td>
                    <td class="text-center">{{ $i->waiting_count ?: '' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-5">Nothing found.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($items->hasPages())<div class="card-footer">{{ $items->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
