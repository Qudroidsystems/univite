<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcademicSession;
use App\Models\AccommodationApplication;
use App\Models\LibraryHold;
use App\Models\LibraryLoan;
use App\Services\Accommodation\AccommodationService;
use App\Services\ClearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Clearance, library and hostel at a glance. Actions (resubmitting, renewing, applying) open the portal page in the browser. */
class CampusController extends ApiController
{
    public function __construct(private ClearanceService $clearance, private AccommodationService $accommodation)
    {
    }

    public function clearance(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $rows = $this->clearance->checklist($student);

        return $this->ok($rows->map(fn ($r) => [
            'item' => $r->item?->name, 'required' => (bool) $r->item?->is_required, 'status' => $r->status, 'note' => $r->note,
            'decided_at' => $r->decided_at?->toIso8601String(),
        ])->values()->all(), ['cleared' => $this->clearance->isCleared($student), 'matric_number' => $student->fresh()->matric_number, 'url' => route('my.clearance')]);
    }

    public function library(Request $request): JsonResponse
    {
        $user = $request->user();
        $loans = LibraryLoan::with('copy.item')->where('user_id', $user->id)->whereNull('returned_at')->orderBy('due_at')->get();
        $holds = LibraryHold::with('item')->where('user_id', $user->id)->whereIn('status', ['waiting', 'ready'])->get();

        return $this->ok([
            'loans' => $loans->map(fn ($l) => ['id' => $l->id, 'title' => $l->copy?->item?->title, 'accession_number' => $l->copy?->accession_number,
                'issued_at' => $l->issued_at?->toIso8601String(), 'due_at' => $l->due_at?->toIso8601String(), 'overdue' => $l->isOverdue(), 'renewals' => (int) $l->renewals])->values()->all(),
            'holds' => $holds->map(fn ($h) => ['id' => $h->id, 'title' => $h->item?->title, 'status' => $h->status, 'expires_at' => $h->expires_at?->toIso8601String()])->values()->all(),
        ], ['url' => route('my.library')]);
    }

    public function accommodation(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $session = AcademicSession::current();
        $allocation = $session ? $this->accommodation->current($student, $session) : null;
        $application = $session ? AccommodationApplication::where('student_id', $student->id)->where('academic_session_id', $session->id)->first() : null;

        return $this->ok([
            'session' => $session?->name,
            'allocation' => $allocation ? ['place' => $allocation->place(), 'status' => $allocation->status, 'reserved_until' => $allocation->reserved_until?->toIso8601String(),
                'checked_in_at' => $allocation->checked_in_at?->toIso8601String(), 'invoice_id' => $allocation->invoice_id] : null,
            'application' => $application ? ['status' => $application->status, 'applied_at' => $application->created_at?->toIso8601String()] : null,
        ], ['url' => route('my.accommodation')]);
    }
}
