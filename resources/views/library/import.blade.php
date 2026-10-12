@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Import catalogue', 'icon' => 'ri-upload-2-line', 'back' => [route('library.items.index'), 'Catalogue']])
    @include('academics.partials.alerts')
    <form method="POST" action="{{ route('library.import.store') }}" enctype="multipart/form-data" class="card">@csrf
        <div class="card-body">
            <p>Upload a spreadsheet (CSV or Excel) with column headings in the first row. <strong>Title</strong> is required; these are read when present:
                Authors, ISBN, Publisher, Year, Edition, Call number, Subject and Copies (how many copies; 1 when left out).</p>
            <p class="text-muted fs-13">A row whose ISBN is already in the catalogue adds copies to that title instead of creating a second one. Copies are given accession numbers automatically.</p>
            <div class="row g-2">
                <div class="col-md-7"><input type="file" name="file" accept=".csv,.txt,.xlsx,.xls" class="form-control" required></div>
                <div class="col-md-5"><input name="location" class="form-control" placeholder="Shelf / location for these copies (optional)"></div>
            </div>
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary">Import</button></div>
    </form>
</div></div></div>
@endsection
