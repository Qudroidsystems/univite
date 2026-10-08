{{-- Totals for the bills currently shown + "copy bills" tool --}}
@if ($summary->isNotEmpty())
    <div class="card">
        <div class="card-header"><h6 class="card-title mb-0"><i class="ri-calculator-line me-1"></i>Total per group of students (active compulsory bills in this view)</h6></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Session</th><th>Applies to</th><th class="text-end">Items</th><th class="text-end">Total</th></tr>
                </thead>
                <tbody>
                    @foreach ($summary as $s)
                        <tr>
                            <td>{{ $s['session'] }}</td>
                            <td>{{ $s['target'] }}</td>
                            <td class="text-end">{{ $s['items'] }}</td>
                            <td class="text-end fw-semibold">₦{{ number_format($s['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@can('Copy school bills to another session')
    <div class="mb-3">
        <button type="button" class="btn btn-soft-secondary" data-bs-toggle="modal" data-bs-target="#copy-bills-modal">
            <i class="ri-file-copy-line me-1"></i>Copy bills from another session
        </button>
    </div>

    <div class="modal fade" id="copy-bills-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" action="{{ route('bills.copy') }}" class="modal-content">
                @csrf
                <div class="modal-header bg-light">
                    <h5 class="modal-title">Copy bills</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted fs-13">Copies every active bill from one session into another. Bills that already exist in the target session are left unchanged.</p>
                    <div class="mb-3">
                        <label class="form-label">From session</label>
                        <select name="from_session_id" class="form-select" required>
                            <option value="">Select…</option>
                            @foreach ($sessionOptions as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">To session</label>
                        <select name="to_session_id" class="form-select" required>
                            <option value="">Select…</option>
                            @foreach ($sessionOptions as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Programme <span class="text-muted">(optional)</span></label>
                        <select name="programme_id" class="form-select">
                            <option value="">All programmes</option>
                            @foreach ($programmes as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Change amounts by (%)</label>
                        <input type="number" name="percent" class="form-control" step="0.01" value="0" min="-90" max="500">
                        <div class="form-text">e.g. 10 raises every amount by 10%; leave 0 for an exact copy.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Copy bills</button>
                </div>
            </form>
        </div>
    </div>
@endcan
