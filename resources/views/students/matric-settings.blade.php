@extends('layouts.master')

@section('content')
@php $canSet = auth()->user()->can('Set the matric number format'); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0"><i class="ri-hashtag me-1 align-middle"></i>Matric Number Format</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">Matric Number Format</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <p class="text-muted">The format is used every time an applicant is admitted. The running number restarts for each different prefix, so with the default format every programme starts at 0001 in each entry year. Existing matric numbers never change.</p>

                            <form method="POST" action="{{ route('students.settings.update') }}" id="matric-form">
                                @csrf @method('PUT')
                                <label class="form-label" for="pattern">Format</label>
                                <input type="text" name="pattern" id="pattern" value="{{ old('pattern', $pattern) }}" maxlength="60"
                                       class="form-control form-control-lg font-monospace @if ($errors->has('pattern') || $error) is-invalid @endif" @readonly(! $canSet)>
                                @error('pattern') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                @if ($error && ! $errors->has('pattern')) <div class="invalid-feedback d-block">{{ $error }}</div> @endif

                                <div class="mt-3 p-3 rounded bg-light">
                                    <div class="text-muted fs-12 mb-1">Next matric number @if ($sample) for {{ $sample }}@endif</div>
                                    @if ($preview)
                                        <div class="fs-4 fw-semibold font-monospace">{{ $preview }}</div>
                                    @else
                                        <div class="text-muted">{{ $sample ? 'Fix the format to see an example.' : 'Add an active programme to see an example.' }}</div>
                                    @endif
                                </div>

                                <div class="mt-3 d-flex gap-2">
                                    <button type="submit" formmethod="get" formaction="{{ route('students.settings') }}" class="btn btn-light">Preview</button>
                                    @if ($canSet)
                                        <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save format</button>
                                    @endif
                                </div>
                                @if ($pattern !== $saved)
                                    <div class="form-text">Previewing only. Currently saved: <code>{{ $saved }}</code></div>
                                @endif
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Parts you can use</h6></div>
                        <ul class="list-group list-group-flush">
                            @foreach ($tokens as $token => $meaning)
                                <li class="list-group-item">
                                    <code>{{ '{' . $token . '}' }}</code>
                                    <div class="text-muted fs-12">{{ $meaning }}</div>
                                </li>
                            @endforeach
                        </ul>
                        <div class="card-footer text-muted fs-12">
                            Anything else you type (slashes, dashes, letters) is kept as written. Examples: <code>{YEAR}/{PROGRAMME_CODE}/{SEQ:4}</code> · <code>{DEPARTMENT_CODE}/{SHORT_YEAR}/{SEQ:3}</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
