@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Course Materials', 'icon' => 'ri-folder-open-line', 'subtitle' => 'Notes, slides and links for your students. They open them in the mobile app.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="GET" class="card"><div class="card-body d-flex gap-2 align-items-end">
        <div style="flex:1;min-width:240px"><label class="form-label mb-1">Course</label>
            <select name="course" class="form-select" onchange="this.form.submit()">@foreach ($courses as $c)<option value="{{ $c->id }}" @selected($course?->id === $c->id)>{{ $c->code }} · {{ $c->title }}</option>@endforeach</select></div>
    </div></form>
    @if ($course)
        <form method="POST" action="{{ route('campus.materials.store', $course->id) }}" enctype="multipart/form-data" class="card" data-no-ajax>@csrf
            <div class="card-header"><h6 class="card-title mb-0">Add to {{ $course->code }}</h6></div>
            <div class="card-body row g-3">
                <div class="col-md-6"><label class="form-label">Title</label><input name="title" class="form-control" required maxlength="150" placeholder="Week 3 slides"></div>
                <div class="col-md-6"><label class="form-label">File (up to {{ \App\Services\Campus\MaterialService::MAX_MB }} MB)</label><input type="file" name="file" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Or a link</label><input name="link" type="url" class="form-control" placeholder="https://"></div>
                <div class="col-md-6"><label class="form-label">Note</label><input name="description" class="form-control" maxlength="1000"></div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary"><i class="ri-upload-2-line me-1"></i>Add</button></div>
        </form>
        <div class="card"><ul class="list-group list-group-flush">
            @forelse ($materials as $m)
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <div><span class="fw-semibold">{{ $m->title }}</span> <span class="text-muted fs-12">{{ $m->session?->name }} · {{ $m->user?->name }} · {{ $m->created_at->format('j M Y') }} · {{ $m->downloads }} download(s)</span>
                        @if ($m->description)<div class="fs-13 text-muted">{{ $m->description }}</div>@endif</div>
                    <div class="d-flex gap-1">
                        @if ($m->path)<a href="{{ route('campus.materials.download', $m->id) }}" class="btn btn-sm btn-light">Download</a>@endif
                        @if ($m->link)<a href="{{ $m->link }}" target="_blank" rel="noopener" class="btn btn-sm btn-light">Open link</a>@endif
                        <form method="POST" action="{{ route('campus.materials.delete', $m->id) }}" data-confirm="Remove this?">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger">Remove</button></form>
                    </div>
                </li>
            @empty
                <li class="list-group-item text-center text-muted py-4">Nothing shared for {{ $course->code }} yet.</li>
            @endforelse
        </ul></div>
    @endif
</div></div></div>
@endsection
