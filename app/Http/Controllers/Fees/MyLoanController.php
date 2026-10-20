<?php

namespace App\Http\Controllers\Fees;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentLoan;
use App\Services\Fees\FeeAccess;
use App\Services\Fees\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A student's own school loans: apply, follow the decision and the repayment plan; and the page shown when fees lock the portal. */
class MyLoanController extends Controller
{
    public function __construct(private LoanService $loans, private FeeAccess $access)
    {
    }

    private function student(): Student
    {
        return auth()->user()->student ?? abort(403, 'This page is for students.');
    }

    public function index(): View
    {
        $student = $this->student();
        $invoice = $this->loans->invoiceFor($student);

        return view('loans.mine', [
            'pagetitle' => 'My school loans', 'student' => $student, 'loans' => StudentLoan::with('session', 'invoice', 'repayments')->where('student_id', $student->id)->latest('id')->get(),
            'cannot' => $this->loans->cannotApply($student), 'invoice' => $invoice, 'max' => $invoice ? $this->loans->maxAmount($invoice) : 0,
            'guarantor' => LoanService::get('require_guarantor') === '1', 'terms' => LoanService::get('terms'), 'enabled' => LoanService::enabled(),
            'standing' => $this->access->standing($student),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000'], 'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_phone' => ['nullable', 'string', 'max:30'], 'guarantor_relationship' => ['nullable', 'string', 'max:50'], 'guarantor_address' => ['nullable', 'string', 'max:255'], 'accept' => ['accepted']],
            ['accept.accepted' => 'Accept the loan terms to apply.']);
        try {
            $loan = $this->loans->apply($this->student(), $d);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Your loan application {$loan->reference} has been sent to the bursary.");
    }

    public function cancel(StudentLoan $loan): RedirectResponse
    {
        abort_unless($loan->student_id === $this->student()->id, 404);
        try {
            $this->loans->cancel($loan, auth()->user(), 'Withdrawn by the student');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Application withdrawn.');
    }

    public function locked(): View|RedirectResponse
    {
        $student = $this->student();
        $reason = $this->access->blocker($student, 'portal');
        if (! $reason) {
            return redirect()->route('dashboard');
        }

        return view('fees.blocked', ['pagetitle' => 'Portal locked', 'title' => 'Your portal is locked until you pay', 'reason' => $reason, 'standing' => $this->access->standing($student)]);
    }
}
