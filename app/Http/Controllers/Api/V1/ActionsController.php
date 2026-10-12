<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AcademicSession;
use App\Models\AccommodationApplication;
use App\Models\ClearanceItem;
use App\Models\CourseResult;
use App\Models\IdCard;
use App\Models\Invoice;
use App\Models\LibraryHold;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\Payment;
use App\Models\Poll;
use App\Models\ResultComplaint;
use App\Data\Polls\Ballot;
use App\Services\Accommodation\AccommodationService;
use App\Services\Accommodation\AccommodationSettings;
use App\Services\ClearanceService;
use App\Services\IdCards\IdCardService;
use App\Services\Library\LibraryService;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Polls\PollService;
use App\Services\RegistrationService;
use App\Services\ResultComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Things students and staff do in the app instead of on the website: register courses, pay fees (the gateway page
 * opens inside the app), query a result, apply for a hostel, renew library books, resubmit clearance, request an ID
 * card, vote, and change their password. Each uses the same service as the portal page, so the same rules apply.
 */
class ActionsController extends ApiController
{
    private function fail(RuntimeException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => ['action' => [$e->getMessage()]]], 422);
    }

    // ---- Course registration ----------------------------------------------------------------------------------

    /** GET /registration: everything the registration screen needs. */
    public function registration(Request $request, RegistrationService $registration): JsonResponse
    {
        $student = $this->student($request)->load('programme');
        $ctx = $registration->context();
        $blockers = $registration->blockers($student, $ctx);
        $form = $ctx ? $registration->form($student, $ctx)->load('courses.course') : null;
        $limits = $registration->limits($student, $ctx);
        $selected = $form ? $form->courses->pluck('course_id')->all() : [];
        $offered = ($ctx && ! $blockers) || ($form && ! $form->isEditable()) ? $registration->offered($student, $ctx) : collect();

        return $this->ok([
            'term' => $ctx?->label(),
            'level' => $student->current_level,
            'blockers' => $blockers,
            'can_edit' => $ctx !== null && ! $blockers && $form?->isEditable(),
            'form' => $form ? ['id' => $form->id, 'status' => $form->status, 'total_units' => (int) $form->total_units, 'note' => $form->note,
                'submitted_at' => $form->submitted_at?->toIso8601String(), 'decided_at' => $form->decided_at?->toIso8601String()] : null,
            'limits' => ['min' => $limits->min, 'max' => $limits->max, 'carryovers_exempt' => $limits->carryoversExempt, 'probation' => $limits->probation],
            'offered' => $offered->map(fn ($r) => [
                'id' => $r['course']->id, 'code' => $r['course']->code, 'title' => $r['course']->title, 'units' => (int) $r['course']->credit_units,
                'status' => $r['status'], 'carryover' => $r['carryover'], 'blocked' => $r['blocked'], 'selected' => in_array($r['course']->id, $selected, true),
            ])->values()->all(),
            'problems' => $form && $form->isEditable() && $form->courses->isNotEmpty() ? $registration->problems($form, $ctx) : [],
        ]);
    }

    /** POST /registration {courses: [ids], submit: bool} */
    public function saveRegistration(Request $request, RegistrationService $registration): JsonResponse
    {
        $d = $request->validate(['courses' => ['nullable', 'array'], 'courses.*' => ['integer'], 'submit' => ['nullable', 'boolean']]);
        $student = $this->student($request);
        $ctx = $registration->context() ?? abort(404, 'No semester is running.');
        try {
            $form = $registration->select($registration->form($student, $ctx), $d['courses'] ?? [], $ctx);
            $message = 'Saved. Submit when you are ready.';
            if ($request->boolean('submit')) {
                $form = $registration->submit($form, $ctx);
                $message = $form->status === 'approved' ? 'Your registration is complete.' : 'Sent for approval.';
            }
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->registration($request, $registration)->getData(true)['data'], ['message' => $message]);
    }

    // ---- Paying fees inside the app ----------------------------------------------------------------------------

    private function ownInvoice(Request $request, Invoice $invoice): void
    {
        $student = $this->student($request);
        abort_unless($invoice->payable_type === $student::class && $invoice->payable_id === $student->id, 404);
    }

    /** GET /fees/{invoice}/pay: the ways to pay and the least that can be paid now. */
    public function payOptions(Request $request, Invoice $invoice, OnlinePaymentService $payments): JsonResponse
    {
        $this->ownInvoice($request, $invoice);

        return $this->ok([
            'gateways' => collect($payments->choices())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'outstanding' => (float) $invoice->outstanding,
            'minimum' => (float) $invoice->minimumPayment(),
            'blocker' => $payments->blocker($invoice),
        ]);
    }

    /**
     * POST /fees/{invoice}/pay {gateway, amount}: starts the payment. The app opens checkout.url (GET) or posts
     * checkout.fields to it (POST) in a web view, and closes it when the page reaches a URL starting with return_prefix,
     * then calls POST /payments/{reference}/confirm.
     */
    public function pay(Request $request, Invoice $invoice, OnlinePaymentService $payments): JsonResponse
    {
        $this->ownInvoice($request, $invoice);
        $d = $request->validate(['gateway' => ['required', Rule::in(array_keys($payments->choices()))], 'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999']]);
        $reference = null;
        try {
            $redirect = $payments->start($invoice, $d['gateway'], (float) $d['amount'], function (Payment $p) use (&$reference) {
                $reference = $p->reference;

                return route('payments.return', $p->reference);
            });
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok([
            'reference' => $reference,
            'checkout' => ['url' => $redirect->url, 'method' => $redirect->method, 'fields' => (object) $redirect->fields],
            'return_prefix' => url('payments/return'),
        ]);
    }

    /** POST /payments/{reference}/confirm: ask the gateway how the payment went. */
    public function confirmPayment(Request $request, string $reference, OnlinePaymentService $payments): JsonResponse
    {
        $student = $this->student($request);
        $payment = Payment::with('invoice')->where('reference', $reference)->firstOrFail();
        abort_unless($payment->invoice && $payment->invoice->payable_type === $student::class && $payment->invoice->payable_id === $student->id, 404);
        try {
            $payment = $payments->confirm($payment);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->ok([
            'reference' => $payment->reference, 'status' => $payment->status, 'amount' => (float) $payment->amount,
            'message' => match ($payment->status) {
                'successful' => 'Payment of ₦' . number_format((float) $payment->amount, 2) . ' received. Your receipt has been emailed to you.',
                'failed' => 'The payment did not go through' . ($payment->note ? ': ' . $payment->note : '.') . ' You were not charged.',
                default => 'Waiting for the bank to confirm. If you paid, it will show here once confirmed; do not pay again.',
            },
            'receipt_url' => $payment->status === 'successful' ? route('my.receipt', $payment) : null,
            'invoice_id' => $payment->invoice_id,
        ]);
    }

    // ---- Result queries ------------------------------------------------------------------------------------------

    /** GET /results/queries: the student's queries and their answers. */
    public function queries(Request $request): JsonResponse
    {
        $student = $this->student($request);

        return $this->ok(ResultComplaint::with('result.course')->where('student_id', $student->id)->latest()->get()->map(fn ($c) => [
            'id' => $c->id, 'result_id' => $c->course_result_id, 'course' => $c->result?->course?->code, 'reason' => $c->reason, 'status' => $c->status,
            'status_label' => ResultComplaint::STATUSES[$c->status] ?? $c->status, 'response' => $c->response, 'created_at' => $c->created_at?->toIso8601String(),
        ])->values()->all());
    }

    /** POST /results/{result}/query {reason} */
    public function query(Request $request, CourseResult $result, ResultComplaintService $complaints): JsonResponse
    {
        $student = $this->student($request);
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        try {
            $c = $complaints->lodge($student, $result, $d['reason']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['id' => $c->id, 'status' => $c->status], ['message' => 'Your query has been sent. The answer will appear here.'], 201);
    }

    // ---- Accommodation ---------------------------------------------------------------------------------------------

    /** GET /accommodation/options: hostels the student may choose, whether applications are open. */
    public function hostelOptions(Request $request, AccommodationService $accommodation, AccommodationSettings $settings): JsonResponse
    {
        $student = $this->student($request);
        $session = AcademicSession::current();
        $app = $session ? AccommodationApplication::where('student_id', $student->id)->where('academic_session_id', $session->id)->first() : null;

        return $this->ok([
            'open' => $settings->on('applications_open'),
            'max_choices' => max(1, $settings->int('max_choices')),
            'instructions' => $settings->get('instructions'),
            'applied' => $app ? ['status' => $app->status, 'preferences' => $app->preferences ?? null, 'applied_at' => $app->created_at?->toIso8601String()] : null,
            'hostels' => $accommodation->hostelsFor($student)->map(fn ($h) => [
                'id' => $h->id, 'name' => $h->name, 'description' => $h->description, 'gender' => $h->gender,
                'rate' => $session ? $h->rateFor($session) : null, 'space' => $session ? $accommodation->freeBed($h, $session) !== null : false,
            ])->values()->all(),
        ]);
    }

    /** POST /accommodation/apply {preferences: [hostel ids in order]} */
    public function applyHostel(Request $request, AccommodationService $accommodation): JsonResponse
    {
        $student = $this->student($request);
        $d = $request->validate(['preferences' => ['required', 'array', 'min:1'], 'preferences.*' => ['nullable', 'integer']]);
        $session = AcademicSession::current() ?? abort(404);
        try {
            $accommodation->apply($student, $session, $d['preferences']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['applied' => true], ['message' => 'Application received. You will be told when beds are given out.'], 201);
    }

    /** POST /accommodation/withdraw */
    public function withdrawHostel(Request $request, AccommodationService $accommodation): JsonResponse
    {
        $student = $this->student($request);
        $session = AcademicSession::current();
        $app = $session ? AccommodationApplication::where('student_id', $student->id)->where('academic_session_id', $session->id)->first() : null;
        abort_unless($app !== null, 404);
        try {
            $accommodation->withdraw($app);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['withdrawn' => true], ['message' => 'Application withdrawn.']);
    }

    // ---- Library ------------------------------------------------------------------------------------------------------

    /** GET /library/search?q= */
    public function librarySearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return $this->ok([]);
        }

        return $this->ok(LibraryItem::search($q)->withCount(['copies as available_count' => fn ($c) => $c->where('status', 'available'), 'copies'])->orderBy('title')->limit(40)->get()
            ->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'authors' => $i->authors, 'year' => $i->year, 'call_number' => $i->call_number,
                'available' => (int) $i->available_count, 'copies' => (int) $i->copies_count, 'is_reference' => (bool) $i->is_reference, 'url' => $i->url])->values()->all());
    }

    public function renew(Request $request, LibraryLoan $loan, LibraryService $library): JsonResponse
    {
        abort_unless($loan->user_id === $request->user()->id, 404);
        try {
            $library->renew($loan, true);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['due_at' => $loan->fresh()->due_at?->toIso8601String()], ['message' => 'Renewed. Now due ' . $loan->fresh()->due_at?->format('j M Y') . '.']);
    }

    public function hold(Request $request, LibraryItem $item, LibraryService $library): JsonResponse
    {
        try {
            $library->placeHold($item, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['held' => true], ['message' => 'You are on the waiting list for "' . $item->title . '".'], 201);
    }

    public function cancelHold(Request $request, LibraryHold $hold, LibraryService $library): JsonResponse
    {
        abort_unless($hold->user_id === $request->user()->id, 404);
        try {
            $library->cancelHold($hold);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['cancelled' => true], ['message' => 'Request cancelled.']);
    }

    // ---- Clearance --------------------------------------------------------------------------------------------------

    public function resubmitClearance(Request $request, ClearanceItem $item, ClearanceService $clearance): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        try {
            $clearance->resubmit($this->student($request), $item, $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['resubmitted' => true], ['message' => 'Sent back for another look.']);
    }

    // ---- ID card --------------------------------------------------------------------------------------------------------

    /** GET /id-card: the person's valid card (with the QR text security scans), any pending request and what is stopping one. */
    public function idCard(Request $request, IdCardService $cards): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->student || $user->staffProfile, 403, 'This is for students and staff.');
        $card = $cards->current($user);
        $pending = $cards->pendingRequest($user);

        return $this->ok([
            'card' => $card ? [
                'serial' => $card->serial, 'kind' => $card->kind, 'status' => $card->status, 'valid' => ! $card->isExpired(),
                'name' => $card->snap('name'), 'number' => $card->snap('number'), 'type' => $card->snap('type'),
                'line1' => $card->snap('line1'), 'line2' => $card->snap('line2'), 'line3' => $card->snap('line3'), 'blood' => $card->snap('blood'),
                'photo_url' => $card->photoUrl(), 'issued_at' => $card->issued_at?->toIso8601String(), 'expires_on' => $card->expires_on?->toDateString(),
                'qr' => route('id-card.check', $card->token),
            ] : null,
            'pending' => $pending ? ['reason' => $pending->reason, 'requested_at' => $pending->requested_at?->toIso8601String(), 'invoice_id' => $pending->invoice_id] : null,
            'problem' => $cards->problem($user),
            'reasons' => collect(IdCard::REASONS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
        ]);
    }

    public function requestIdCard(Request $request, IdCardService $cards): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'in:' . implode(',', array_keys(IdCard::REASONS))], 'note' => ['nullable', 'string', 'max:1000']]);
        try {
            $card = $cards->request($request->user(), $d['reason'], $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['requested' => true, 'invoice_id' => $card->invoice_id], ['message' => $card->invoice ? 'Request received. Pay the replacement fee under Fees; the card is issued once it is paid.' : 'Request received. You will be told when your card is ready.'], 201);
    }

    // ---- Polls & elections ----------------------------------------------------------------------------------------------

    public function polls(Request $request, PollService $polls): JsonResponse
    {
        return $this->ok($polls->forUser($request->user())->map(fn (Poll $p) => [
            'id' => $p->id, 'title' => $p->title, 'kind' => $p->kind, 'phase' => $p->phase(), 'opens_at' => $p->opens_at?->toIso8601String(),
            'closes_at' => $p->closes_at?->toIso8601String(), 'voted' => (bool) $p->has_voted, 'results_visible' => $p->resultsVisible(),
        ])->values()->all());
    }

    public function poll(Request $request, Poll $poll, PollService $polls): JsonResponse
    {
        $user = $request->user();
        $voted = $polls->hasVoted($poll, $user);
        abort_unless($poll->status !== 'draft' && ($voted || $polls->isEligible($poll, $user)), 404);
        $poll->load('questions.options');
        $results = $poll->resultsVisible() ? $polls->results($poll) : null;

        return $this->ok([
            'id' => $poll->id, 'title' => $poll->title, 'description' => $poll->description, 'kind' => $poll->kind, 'is_secret' => (bool) $poll->is_secret,
            'phase' => $poll->phase(), 'opens_at' => $poll->opens_at?->toIso8601String(), 'closes_at' => $poll->closes_at?->toIso8601String(),
            'voted' => $voted, 'cannot_vote' => $polls->cannotVote($poll, $user),
            'questions' => $poll->questions->sortBy('sort_order')->map(fn ($q) => [
                'id' => $q->id, 'prompt' => $q->prompt, 'type' => $q->type, 'max_choices' => $q->max_choices, 'required' => (bool) $q->is_required,
                'options' => $q->options->sortBy('sort_order')->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'description' => $o->description, 'photo_url' => $o->photo_url])->values()->all(),
            ])->values()->all(),
            'results' => $results ? ['eligible' => $results->eligible, 'voted' => $results->voted, 'turnout' => $results->turnout(),
                'questions' => collect($results->questions)->map(fn ($r) => ['question_id' => $r->questionId, 'prompt' => $r->prompt, 'ballots' => $r->ballots, 'tie' => $r->tie,
                    'options' => collect($r->options)->map(fn ($o) => ['option_id' => $o->optionId, 'label' => $o->label, 'votes' => $o->votes, 'percent' => $o->percent, 'leading' => $o->leading, 'photo_url' => $o->photoUrl])->all()])->all()] : null,
        ]);
    }

    /** POST /polls/{poll}/vote {answers: {question id: option id | [option ids] | text}} */
    public function vote(Request $request, Poll $poll, PollService $polls): JsonResponse
    {
        $d = $request->validate(['answers' => ['nullable', 'array']]);
        try {
            $polls->vote($poll, $request->user(), Ballot::fromArray($d['answers'] ?? []));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['voted' => true], ['message' => 'Thank you. Your vote has been counted' . ($poll->is_secret ? ' and nobody can see how you voted.' : '.')]);
    }

    // ---- Account ------------------------------------------------------------------------------------------------------

    /** PUT /me/password {current_password, password, password_confirmation} */
    public function password(Request $request): JsonResponse
    {
        $d = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed']]);
        $user = $request->user();
        if (! Hash::check($d['current_password'], $user->password)) {
            return response()->json(['message' => 'Your current password is not right.', 'errors' => ['current_password' => ['Your current password is not right.']]], 422);
        }
        $user->update(['password' => $d['password'], 'must_change_password' => false]);
        // Other phones signed in with the old password are signed out; this one stays.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return $this->ok(['changed' => true], ['message' => 'Password changed. Other phones were signed out.']);
    }
}
