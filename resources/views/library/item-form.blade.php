@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $item->exists ? 'Edit ' . $item->title : 'Add a title', 'icon' => 'ri-book-2-line', 'back' => [$item->exists ? route('library.items.show', $item) : route('library.items.index'), $item->exists ? 'Title' : 'Catalogue']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ $item->exists ? route('library.items.update', $item) : route('library.items.store') }}" class="card">@csrf @if ($item->exists) @method('PUT') @endif
        <div class="card-body row g-3">
            <div class="col-md-8"><label class="form-label">Title</label><input name="title" value="{{ old('title', $item->title) }}" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Kind</label><select name="kind" class="form-select">@foreach (\App\Models\LibraryItem::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $item->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-8"><label class="form-label">Authors</label><input name="authors" value="{{ old('authors', $item->authors) }}" class="form-control" placeholder="Surname, Initials; Surname, Initials"></div>
            <div class="col-md-4"><label class="form-label">ISBN / ISSN</label><input name="isbn" value="{{ old('isbn', $item->isbn) }}" class="form-control"></div>
            <div class="col-md-5"><label class="form-label">Publisher</label><input name="publisher" value="{{ old('publisher', $item->publisher) }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Year</label><input name="year" type="number" value="{{ old('year', $item->year) }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Edition</label><input name="edition" value="{{ old('edition', $item->edition) }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Call number</label><input name="call_number" value="{{ old('call_number', $item->call_number) }}" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Subject</label><input name="subject" value="{{ old('subject', $item->subject) }}" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Link (electronic resources)</label><input name="url" type="url" value="{{ old('url', $item->url) }}" class="form-control"></div>
            <div class="col-12"><label class="form-label">Description</label><textarea name="description" rows="3" class="form-control">{{ old('description', $item->description) }}</textarea></div>
            <div class="col-12"><div class="form-check"><input type="hidden" name="is_reference" value="0"><input type="checkbox" name="is_reference" value="1" id="ref" class="form-check-input" @checked(old('is_reference', $item->is_reference))><label class="form-check-label" for="ref">For use in the library only (not lent out)</label></div></div>
            @unless ($item->exists)
                <div class="col-12"><hr class="my-1"><div class="fw-semibold">Copies</div></div>
                <div class="col-md-3"><label class="form-label">Number of copies</label><input name="copies" type="number" min="0" max="500" value="{{ old('copies', 1) }}" class="form-control"><div class="form-text">Numbered automatically. You can type your own numbers on the next page.</div></div>
                <div class="col-md-5"><label class="form-label">Shelf / location</label><input name="location" value="{{ old('location') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Price per copy (₦)</label><input name="price" type="number" step="0.01" min="0" value="{{ old('price') }}" class="form-control"><div class="form-text">Charged if a borrower loses it.</div></div>
            @endunless
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary">Save</button></div>
    </form>
</div></div></div>
@endsection
