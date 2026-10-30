<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Models\AdmissionDeferral;
use App\Services\Students\StudentRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Students who deferred, the session they return in, and bringing them back. */
class DeferralController extends Controller
{
    public function __construct(private StudentRecordService $records)
    {
        $this->middleware('permission:View students')->only('index');
        $this->middleware('permission:Manage deferred admissions')->only('resume');
    }

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['deferred', 'resumed'], true) ? $request->query('status') : 'deferred';

        return view('students.deferrals', [
            'pagetitle' => 'Deferred admissions', 'status' => $status,
            'rows' => AdmissionDeferral::with('student.programme', 'fromSession', 'toSession', 'by')->where('status', $status)->latest('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function resume(Request $request, AdmissionDeferral $deferral): RedirectResponse
    {
        try {
            $this->records->resume($deferral, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $deferral->student->full_name . ' has resumed.');
    }
}
