@extends('layouts.master')

@section('content')
@php use App\Services\DocumentMailer; $types = DocumentMailer::TYPES; @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Emailed documents</h4></div>
            @include('academics.partials.alerts')
            <p class="text-muted">Every receipt, course form, slip and notice the portal produces is emailed to the student or applicant it belongs to. Each attempt is listed here.</p>
            @if ($current === 'log')<div class="alert alert-warning">Emails are not being sent: the portal is set to write them to the log only. Set up the mail server under Settings below.</div>@endif
            @if ($failed)<div class="alert alert-danger">{{ $failed }} email(s) failed in the last 7 days. <a href="{{ route('emails.index', ['status' => 'failed']) }}">Show them</a>.</div>@endif

            <div class="row">
                <div class="col-xl-8">
                    <form method="GET" class="card"><div class="card-body row g-2">
                        <div class="col-md-5"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Email address or subject"></div>
                        <div class="col-md-3"><select name="type" class="form-select"><option value="">Any document</option>@foreach ($types as $k => $t)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $t[0] }}</option>@endforeach</select></div>
                        <div class="col-md-2"><select name="status" class="form-select"><option value="">Any result</option><option value="sent" @selected(request('status') === 'sent')>Sent</option><option value="failed" @selected(request('status') === 'failed')>Failed</option></select></div>
                        <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
                    </div></form>
                    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>When</th><th>Document</th><th>To</th><th>Result</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($emails as $e)
                            <tr><td class="text-nowrap">{{ $e->created_at->format('d M Y, g:i A') }}</td>
                                <td>{{ $e->subject }}<div class="text-muted fs-12">{{ $types[$e->type][0] ?? $e->type }}{{ $e->sender ? ' · by ' . $e->sender->name : '' }}</div></td>
                                <td>{{ $e->recipient }}</td>
                                <td>@if ($e->status === 'sent')<span class="badge bg-success-subtle text-success">Sent</span>@else<span class="badge bg-danger-subtle text-danger">Failed</span><div class="text-muted fs-12">{{ $e->error }}</div>@endif</td>
                                <td class="text-end">@if (($types[$e->type][2] ?? false) && auth()->user()->can('Resend emailed documents'))<form method="POST" action="{{ route('emails.resend', $e) }}">@csrf<button class="btn btn-sm btn-soft-primary">Send again</button></form>@endif</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No documents have been emailed yet.</td></tr>
                        @endforelse
                        </tbody></table></div>
                        <div class="card-footer">{{ $emails->links() }}</div></div>
                </div>

                <div class="col-xl-4">
                    @can('Edit email settings')
                    <form method="POST" action="{{ route('emails.settings') }}" class="card">
                        @csrf @method('PUT')
                        <div class="card-header"><h6 class="card-title mb-0">Which documents are emailed</h6></div>
                        <div class="card-body">
                            @foreach ($types as $k => [$label, $when])
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="documents[]" value="{{ $k }}" id="doc-{{ $k }}" @checked($enabled[$k])>
                                    <label class="form-check-label" for="doc-{{ $k }}">{{ $label }}<div class="text-muted fs-12">{{ $when }}</div></label></div>
                            @endforeach
                        </div>
                        <div class="card-header border-top"><h6 class="card-title mb-0">Mail server</h6></div>
                        <div class="card-body">
                            <div class="mb-2"><label class="form-label">Send email through</label>
                                <select name="mailer" class="form-select">@foreach (\App\Support\MailSettings::MAILERS as $v => $l)<option value="{{ $v }}" @selected((string) $mail['mailer'] === $v)>{{ $l }}</option>@endforeach</select></div>
                            <div class="row g-2">
                                <div class="col-8"><label class="form-label">SMTP host</label><input name="host" value="{{ old('host', $mail['host']) }}" class="form-control @error('host') is-invalid @enderror" placeholder="smtp.gmail.com">@error('host')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div class="col-4"><label class="form-label">Port</label><input name="port" type="number" value="{{ old('port', $mail['port']) }}" class="form-control" placeholder="587"></div>
                                <div class="col-6"><label class="form-label">Security</label><select name="encryption" class="form-select"><option value="">None</option><option value="tls" @selected($mail['encryption'] === 'tls')>TLS (port 587)</option><option value="ssl" @selected($mail['encryption'] === 'ssl')>SSL (port 465)</option></select></div>
                                <div class="col-6"><label class="form-label">Username</label><input name="username" value="{{ old('username', $mail['username']) }}" class="form-control" autocomplete="off"></div>
                                <div class="col-12"><label class="form-label">Password</label><input name="password" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $mail['has_password'] ? 'Saved; leave blank to keep it' : '' }}"></div>
                                <div class="col-12"><label class="form-label">From address</label><input name="from_address" type="email" value="{{ old('from_address', $mail['from_address']) }}" class="form-control" placeholder="portal@university.edu.ng"></div>
                                <div class="col-12"><label class="form-label">From name</label><input name="from_name" value="{{ old('from_name', $mail['from_name']) }}" class="form-control" placeholder="{{ \App\Support\Institution::get('name') }}"></div>
                            </div>
                        </div>
                        <div class="card-footer text-end"><button class="btn btn-primary">Save</button></div>
                    </form>
                    <form method="POST" action="{{ route('emails.test') }}" class="card"><div class="card-body">
                        @csrf
                        <label class="form-label">Send a test email</label>
                        <div class="d-flex gap-2"><input type="email" name="to" value="{{ auth()->user()->email }}" class="form-control" required><button class="btn btn-soft-primary text-nowrap">Send test</button></div>
                    </div></form>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
