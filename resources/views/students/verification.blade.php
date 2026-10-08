@extends('layouts.master')

@section('content')
@php $canVerify = auth()->user()->can('Verify new students'); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row"><div class="col-12"><div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-shield-check-line me-1 align-middle"></i>New Student Verification</h4>
                <div class="page-title-right"><ol class="breadcrumb m-0"><li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li><li class="breadcrumb-item active">Verification</li></ol></div>
            </div></div></div>

            @include('academics.partials.alerts')

            <p class="text-muted">Newly admitted students finish their online steps, then come in with their original documents. Open their uploaded documents here, compare them with the originals, and mark them verified.</p>

            <ul class="nav nav-pills mb-3">
                @foreach (['ready' => 'Ready to verify', 'awaiting' => 'Still doing their steps', 'verified' => 'Verified'] as $key => $label)
                    <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('verification.index', array_merge(request()->only('q', 'batch'), ['tab' => $key])) }}">{{ $label }} <span class="badge bg-light text-body ms-1">{{ $counts[$key] }}</span></a></li>
                @endforeach
            </ul>

            <div class="card">
                <div class="card-header">
                    <form method="GET" class="d-flex flex-wrap gap-2">
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:240px" placeholder="Matric number or name">
                        <select name="batch" class="form-select" style="width:auto" onchange="this.form.submit()">
                            <option value="">All batches</option>@foreach ($batches as $b)<option value="{{ $b }}" @selected(request('batch') === $b)>{{ $b }}</option>@endforeach
                        </select>
                        <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Student</th><th>Programme</th><th>Progress</th><th>Documents</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            @forelse ($students as $s)
                                @php $list = $onboarding->checklist($s); $left = collect($list)->reject(fn ($i) => $i[2] || $i[0] === 'verified'); @endphp
                                <tr>
                                    <td><a href="{{ route('students.show', $s->id) }}" class="fw-semibold">{{ $s->full_name }}</a><div class="text-muted fs-12">{{ $s->matric_label }}@if ($s->admission_batch) · {{ $s->admission_batch }}@endif</div></td>
                                    <td>{{ $s->programme->name }}</td>
                                    <td>
                                        @if ($s->verified_at)
                                            <span class="text-success">Verified {{ $s->verified_at->format('d M Y') }}</span>@if ($s->verification_note)<div class="text-muted fs-12">{{ $s->verification_note }}</div>@endif
                                        @elseif ($left->isEmpty())
                                            <span class="text-success">All steps done</span>
                                        @else
                                            <span class="text-muted fs-12">Still to do: {{ $left->pluck(1)->implode('; ') }}</span>
                                        @endif
                                    </td>
                                    <td class="fs-12">@foreach ($onboarding->documents($s) as $d)<div><a href="{{ route('verification.documents.show', $d->id) }}" target="_blank">{{ $d->label }}</a></div>@endforeach</td>
                                    <td class="text-end">
                                        @if ($canVerify)
                                            @if ($s->verified_at)
                                                <form method="POST" action="{{ route('verification.unverify', $s->id) }}" onsubmit="return confirm('Remove this verification?')">@csrf<button class="btn btn-sm btn-soft-danger">Undo</button></form>
                                            @elseif ($left->isEmpty())
                                                <form method="POST" action="{{ route('verification.verify', $s->id) }}" class="d-flex gap-1 justify-content-end">
                                                    @csrf<input name="note" class="form-control form-control-sm" style="width:160px" placeholder="Note (optional)" maxlength="255">
                                                    <button class="btn btn-sm btn-success" type="submit"><i class="ri-check-line me-1"></i>Verify</button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-5">No students here.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
