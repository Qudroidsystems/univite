@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $item->title, 'icon' => 'ri-book-2-line', 'subtitle' => $item->citation(), 'back' => [route('library.items.index'), 'Catalogue']])
    @include('academics.partials.alerts')
    <div class="row">
        <div class="col-lg-4">
            <div class="card"><div class="card-body">
                <dl class="row mb-0 fs-13">
                    <dt class="col-5">Kind</dt><dd class="col-7">{{ \App\Models\LibraryItem::KINDS[$item->kind] ?? $item->kind }}{{ $item->is_reference ? ' (library use only)' : '' }}</dd>
                    <dt class="col-5">Authors</dt><dd class="col-7">{{ $item->authors ?? '—' }}</dd>
                    <dt class="col-5">Publisher</dt><dd class="col-7">{{ $item->publisher ?? '—' }}</dd>
                    <dt class="col-5">Year / edition</dt><dd class="col-7">{{ $item->year ?? '—' }} {{ $item->edition }}</dd>
                    <dt class="col-5">ISBN</dt><dd class="col-7">{{ $item->isbn ?? '—' }}</dd>
                    <dt class="col-5">Call number</dt><dd class="col-7">{{ $item->call_number ?? '—' }}</dd>
                    <dt class="col-5">Subject</dt><dd class="col-7">{{ $item->subject ?? '—' }}</dd>
                    @if ($item->url)<dt class="col-5">Link</dt><dd class="col-7"><a href="{{ $item->url }}" target="_blank" rel="noopener">Open</a></dd>@endif
                </dl>
                @if ($item->description)<p class="fs-13 mt-2 mb-0">{{ $item->description }}</p>@endif
            </div>
            @can('Edit library catalogue')
                <div class="card-footer d-flex justify-content-between"><a href="{{ route('library.items.edit', $item) }}" class="btn btn-sm btn-light">Edit</a>
                    <form method="POST" action="{{ route('library.items.destroy', $item) }}" onsubmit="return confirm('Remove this title from the catalogue?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form></div>
            @endcan
            </div>
            @if ($item->holds->isNotEmpty())
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Waiting list</h6></div><ol class="list-group list-group-flush list-group-numbered">
                    @foreach ($item->holds as $h)<li class="list-group-item fs-13">{{ $h->user->name }} @if ($h->status === 'ready')<span class="badge bg-warning-subtle text-warning">Kept until {{ $h->expires_at->format('j M') }}</span>@endif</li>@endforeach
                </ol></div>
            @endif
        </div>
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Copies</h6></div>
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Accession no.</th><th>Status</th><th>Location</th><th>Price</th>@can('Edit library catalogue')<th></th>@endcan</tr></thead><tbody>
                    @forelse ($item->copies as $c)
                        <tr>
                            <td class="fw-semibold">{{ $c->accession_number }}</td>
                            <td><span class="badge {{ \App\Models\LibraryCopy::BADGES[$c->status] }}">{{ \App\Models\LibraryCopy::STATUSES[$c->status] }}</span>
                                @if ($c->openLoan)<div class="fs-12 text-muted">{{ $c->openLoan->user->name }}, due {{ $c->openLoan->due_at->format('j M Y') }}</div>@endif</td>
                            <td>{{ $c->location ?? '—' }}</td>
                            <td>{{ $c->price !== null ? '₦' . number_format((float) $c->price, 2) : '—' }}</td>
                            @can('Edit library catalogue')
                                <td class="text-end"><form method="POST" action="{{ route('library.copies.update', $c) }}" class="d-flex gap-1 justify-content-end">@csrf @method('PUT')
                                    <input name="location" value="{{ $c->location }}" class="form-control form-control-sm" style="width:110px" placeholder="Location">
                                    <input name="price" value="{{ $c->price }}" class="form-control form-control-sm" style="width:90px" placeholder="Price">
                                    <select name="status" class="form-select form-select-sm" style="width:130px">@foreach (array_unique(['available', 'repair', 'lost', 'withdrawn', $c->status]) as $s)<option value="{{ $s }}" @selected($c->status === $s)>{{ \App\Models\LibraryCopy::STATUSES[$s] }}</option>@endforeach</select>
                                    <button class="btn btn-sm btn-light">Save</button></form></td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No copies yet.</td></tr>
                    @endforelse
                </tbody></table></div>
                @can('Edit library catalogue')
                    <div class="card-footer"><form method="POST" action="{{ route('library.copies.store', $item) }}" class="row g-2 align-items-end">@csrf
                        <div class="col-md-2"><label class="form-label fs-12">How many</label><input name="count" type="number" min="1" max="500" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label fs-12">or their accession numbers</label><input name="accessions" class="form-control form-control-sm" placeholder="Separated by commas or spaces"></div>
                        <div class="col-md-2"><label class="form-label fs-12">Location</label><input name="location" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><label class="form-label fs-12">Price (₦)</label><input name="price" type="number" step="0.01" min="0" class="form-control form-control-sm"></div>
                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add copies</button></div>
                    </form></div>
                @endcan
            </div>
        </div>
    </div>
</div></div></div>
@endsection
