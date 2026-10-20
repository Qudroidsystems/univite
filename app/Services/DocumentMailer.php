<?php

namespace App\Services;

use App\Mail\DocumentMail;
use App\Models\Applicant;
use App\Models\CourseResult;
use App\Models\DocumentEmail;
use App\Models\Payment;
use App\Models\RegistrationForm;
use App\Models\ResultComplaint;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\Institution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Emails a copy of every receipt, course form, slip and notice the portal produces to the person it belongs to.
 * The email is the document itself (with the university's letterhead) and the same document is attached.
 * Every attempt is logged in document_emails; a failed email never stops the operation that produced the document.
 */
class DocumentMailer
{
    /** type => [label, when it is sent, can be re-sent from the log] */
    public const TYPES = [
        'receipt'            => ['Payment receipts', 'When a payment is confirmed, online or recorded at the bursary.', true],
        'payment_reversal'   => ['Payment reversal notices', 'When a payment is formally reversed.', true],
        'course_form'        => ['Course registration forms', 'When a student submits their courses, and again when the form is approved.', true],
        'clearance_slip'     => ['Clearance slips', 'When a new student is fully cleared.', true],
        'matric_number'      => ['Matric number notices', 'When a matric number is issued, taken back or corrected.', false],
        'application_slip'   => ['Application slips', 'When an applicant submits their application.', true],
        'result_notice'      => ['Result corrections', 'When one of a student\'s published results is corrected, restored or withdrawn.', false],
        'complaint_response' => ['Result query responses', 'When a student\'s result query is answered.', true],
        'graduation'         => ['Graduation notices', 'When a graduation list is approved, or an approval is reversed.', false],
        'leave_letter'       => ['Leave letters', 'When staff leave is applied for, approved, not approved, returned, cancelled, or the person resumes.', false],
        'payslip'            => ['Payslips', 'When a month\'s payroll is approved (if emailing payslips is on in Payroll Settings).', true],
    ];

    public static function enabled(string $type): bool
    {
        return Setting::get("mail.documents.{$type}", '1') === '1';
    }

    // ---- documents ---------------------------------------------------------------------------------

    public function receipt(Payment $payment, ?User $by = null): int
    {
        $payment->loadMissing('invoice.payable', 'invoice.session', 'recorder');
        $invoice = $payment->invoice;
        $payer = $invoice->payable;
        $method = match ($payment->gateway) {
            'manual' => Payment::CHANNELS[$payment->channel] ?? 'Offline',
            'loan' => 'School loan',
            default => ucfirst($payment->gateway),
        };

        return $this->send('receipt', $payment, $this->recipients($payer), "Payment receipt {$payment->reference}", [
            'title'    => 'Official payment receipt',
            'greeting' => $this->greeting($payer),
            'intro'    => ['We have received your payment. This email is your receipt; a copy is attached.'],
            'details'  => array_filter([
                'Receipt no.'          => $payment->reference,
                'Date'                 => ($payment->paid_at ?? $payment->created_at)->format('j M Y, g:i A'),
                'Paid by'              => $payer?->full_name,
                $this->idLabel($payer) => $payer?->identifier,
                'Programme'            => $payer?->programme?->name,
                'Invoice'              => $invoice->reference . ' · ' . $invoice->title,
                'Session'              => $invoice->session?->name,
                'Method'               => $method,
                'Teller / gateway ref' => $payment->gateway_reference,
                'Amount paid'          => '₦' . number_format((float) $payment->amount, 2),
                'Balance on invoice'   => '₦' . number_format($invoice->outstanding, 2),
                'Received by'          => $payment->recorder?->name,
            ], fn ($v) => $v !== null && $v !== ''),
            'signatory' => 'bursar',
            'reference' => $payment->reference,
        ], "Receipt-{$payment->reference}.html", $by);
    }

    public function paymentReversed(Payment $reversal, ?User $by = null): int
    {
        $original = Payment::with('invoice.payable', 'invoice.session')->find($reversal->reverses_payment_id);
        if (! $original) {
            return 0;
        }
        $payer = $original->invoice->payable;

        return $this->send('payment_reversal', $reversal, $this->recipients($payer), "Payment {$original->reference} has been reversed", [
            'title'    => 'Payment reversal notice',
            'banner'   => ['Receipt ' . $original->reference . ' is no longer valid.', 'danger'],
            'greeting' => $this->greeting($payer),
            'intro'    => ['A payment on your account has been formally reversed by the bursary. The original receipt stays on record but no longer counts towards your fees.'],
            'details'  => array_filter([
                'Reversal no.'       => $reversal->reference,
                'Original receipt'   => $original->reference,
                'Amount reversed'    => '₦' . number_format(abs((float) $reversal->amount), 2),
                'Invoice'            => $original->invoice->reference . ' · ' . $original->invoice->title,
                'Reason'             => $original->reversal_reason,
                'Reversed on'        => $original->reversed_at?->format('j M Y, g:i A'),
                'Balance now owed'   => '₦' . number_format($original->invoice->fresh()->outstanding, 2),
            ]),
            'outro'     => ['If you believe this is a mistake, contact the bursary with your original receipt.'],
            'signatory' => 'bursar',
            'reference' => $reversal->reference,
        ], "Reversal-{$reversal->reference}.html", $by);
    }

    public function courseForm(RegistrationForm $form, ?User $by = null): int
    {
        $form->loadMissing('student.user', 'student.programme', 'session', 'semesterType', 'courses.course.department', 'decider');
        $student = $form->student;
        $approved = $form->status === 'approved';

        return $this->send('course_form', $form, $this->recipients($student), ($approved ? 'Approved course form' : 'Course form submitted') . " · {$form->session->name} {$form->semesterType->name}", [
            'title'    => 'Course registration form · ' . $form->session->name . ' · ' . $form->semesterType->name,
            'banner'   => $approved ? ['Approved' . ($form->decided_at ? ' on ' . $form->decided_at->format('j M Y') : ''), 'success'] : ['Submitted, awaiting approval by your course adviser', 'warning'],
            'greeting' => $this->greeting($student),
            'intro'    => [$approved ? 'Your course registration is complete. Your approved course form is below and attached.' : 'We have received your course registration. A copy is below; you will get the approved form by email once it is approved.'],
            'details'  => ['Name' => $student->full_name, 'Matric number' => $student->matric_label, 'Programme' => $student->programme?->name, 'Level' => (string) $form->level],
            'table'    => [
                'head'  => ['Code', 'Title', 'Type', 'Department', 'Units'], 'right' => [4],
                'rows'  => $form->courses->sortBy('course.code')->map(fn ($r) => [$r->course->code, $r->course->title . ($r->is_carryover ? ' (carry-over)' : ''), \App\Support\CourseType::label($r->course_status), (string) $r->course->department?->name, (string) $r->credit_units])->values()->all(),
                'foot'  => [['', '', '', 'Total units', (string) $form->total_units]],
            ],
            'signatory' => $approved ? 'registrar' : null,
            'link'      => [route('my.registration'), 'Open my registration'],
            'reference' => 'Form #' . $form->id,
        ], 'Course-form-' . Str::slug($form->session->name . '-' . $form->semesterType->name) . '.html', $by);
    }

    public function clearanceSlip(Student $student, ?User $by = null): int
    {
        $student->loadMissing('user', 'programme');
        $rows = $student->clearances()->with('item')->get()->filter(fn ($r) => $r->item)->sortBy(fn ($r) => [$r->item->sort_order, $r->item->id]);

        return $this->send('clearance_slip', $student, $this->recipients($student), 'Your clearance slip', [
            'title'    => 'Clearance slip',
            'banner'   => ['Fully cleared' . ($student->cleared_at ? ' on ' . $student->cleared_at->format('j M Y') : ''), 'success'],
            'greeting' => $this->greeting($student),
            'intro'    => ['You have completed clearance. Your clearance slip is below and attached; keep it safe.'],
            'details'  => array_filter(['Name' => $student->full_name, 'Matric number' => $student->matric_label, 'Programme' => $student->programme?->name, 'Entry level' => (string) $student->entry_level]),
            'table'    => ['head' => ['Item', 'Status', 'Date'], 'rows' => $rows->map(fn ($r) => [$r->item->name, ucfirst($r->status), $r->decided_at?->format('j M Y') ?? ''])->values()->all()],
            'signatory' => 'registrar',
            'link'      => [route('my.clearance'), 'Open my clearance'],
        ], 'Clearance-slip.html', $by);
    }

    /** $kind: issued | taken_back | corrected */
    public function matricNumber(Student $student, string $kind = 'issued', ?string $old = null, ?string $reason = null, ?User $by = null): int
    {
        $student->loadMissing('user', 'programme');
        [$subject, $intro, $banner] = match ($kind) {
            'taken_back' => ['Your matric number has been withdrawn', "The matric number {$old} that was issued to you has been withdrawn because it was issued in error. You will receive a new one shortly; until then sign in with your application number.", [$old . ' is no longer valid', 'danger']],
            'corrected'  => ['Your matric number has been corrected', "Your matric number has been corrected from {$old} to {$student->matric_number}. Use the new number from now on, including to sign in. Documents showing the old number remain on record.", ['Your new matric number is ' . $student->matric_number, 'success']],
            default      => ['Your matric number', "Your matric number is {$student->matric_number}. You can now use it to sign in to the portal.", ['Matric number: ' . $student->matric_number, 'success']],
        };

        return $this->send('matric_number', $student, $this->recipients($student), $subject, [
            'title' => 'Matric number', 'banner' => $banner, 'greeting' => $this->greeting($student), 'intro' => [$intro],
            'details' => array_filter(['Name' => $student->full_name, 'Programme' => $student->programme?->name, 'Previous number' => $old, 'Reason' => $reason]),
            'signatory' => 'registrar',
        ], null, $by);
    }

    public function applicationSlip(Applicant $applicant, ?User $by = null): int
    {
        $applicant->loadMissing('programme', 'session');

        return $this->send('application_slip', $applicant, $this->recipients($applicant), "Application received · {$applicant->application_no}", [
            'title'    => 'Application slip',
            'banner'   => ['Submitted on ' . ($applicant->submitted_at ?? now())->format('j M Y, g:i A'), 'success'],
            'greeting' => $this->greeting($applicant),
            'intro'    => ['We have received your application. Your application slip is below and attached. Quote your application number in every enquiry.'],
            'details'  => array_filter([
                'Application number' => $applicant->application_no, 'Name' => $applicant->full_name, 'Programme' => $applicant->programme?->name,
                'Session' => $applicant->session?->name, 'JAMB registration no.' => $applicant->jamb_reg_no, 'JAMB score' => $applicant->jamb_score ? (string) $applicant->jamb_score : null,
                'Entry mode' => $applicant->entry_mode ? \App\Models\EntryMode::label($applicant->entry_mode) : null,
            ]),
            'signatory' => 'registrar',
            'reference' => $applicant->application_no,
        ], 'Application-slip-' . str_replace('/', '-', (string) $applicant->application_no) . '.html', $by);
    }

    /** $kind: amended | restored | withdrawn */
    public function resultNotice(CourseResult $result, string $kind, ?array $before, ?string $reason, ?User $by = null): int
    {
        $result->loadMissing('student.user', 'course', 'session', 'semesterType');
        $student = $result->student;
        $course = $result->course;
        $was = $before ? (($before['ca'] ?? '') . ' + ' . ($before['exam'] ?? '') . ' (' . ($before['grade'] ?? '—') . ')') : null;
        $now = $kind === 'withdrawn' ? 'Withdrawn for review' : "{$result->ca} + {$result->exam} = {$result->total} ({$result->grade})";

        return $this->send('result_notice', $result, $this->recipients($student), ($kind === 'withdrawn' ? 'Result withdrawn' : 'Result corrected') . " · {$course->code}", [
            'title'    => $kind === 'withdrawn' ? 'Result withdrawal notice' : 'Result correction notice',
            'greeting' => $this->greeting($student),
            'intro'    => [$kind === 'withdrawn'
                ? "Your published result in {$course->code} has been withdrawn for review. It no longer counts in your GPA until it is published again."
                : "Your published result in {$course->code} has been corrected. The earlier result stays on record."],
            'details'  => array_filter([
                'Course' => "{$course->code} · {$course->title}", 'Session' => trim(($result->session?->name ?? '') . ' ' . ($result->semesterType?->name ?? '')),
                'Previous result' => $was, 'Result now' => $now, 'Reason' => $reason,
            ]),
            'signatory' => 'registrar',
            'link'      => [route('my.results'), 'Open my results'],
        ], null, $by);
    }

    public function complaintAnswered(ResultComplaint $complaint, ?User $by = null): int
    {
        $complaint->loadMissing('student.user', 'result.course');

        return $this->send('complaint_response', $complaint, $this->recipients($complaint->student), "Your result query on {$complaint->result?->course?->code} has been answered", [
            'title'    => 'Result query response',
            'banner'   => [ResultComplaint::STATUSES[$complaint->status] ?? ucfirst($complaint->status), $complaint->status === 'upheld' ? 'success' : 'warning'],
            'greeting' => $this->greeting($complaint->student),
            'intro'    => ['Your query about a published result has been reviewed.'],
            'details'  => array_filter([
                'Course' => $complaint->result?->course ? $complaint->result->course->code . ' · ' . $complaint->result->course->title : null,
                'Your query' => $complaint->reason, 'Decision' => ResultComplaint::STATUSES[$complaint->status] ?? $complaint->status,
                'Response' => $complaint->response, 'Result now' => $complaint->result ? "{$complaint->result->total} ({$complaint->result->grade})" : null,
            ]),
            'link' => [route('my.results'), 'Open my results'],
        ], null, $by);
    }

    public function graduation(Student $student, bool $approved, ?string $reason = null, ?User $by = null): int
    {
        $student->loadMissing('user', 'programme');

        return $this->send('graduation', $student, $this->recipients($student), $approved ? 'Congratulations: you have graduated' : 'Your graduation approval has been reversed', [
            'title'    => $approved ? 'Graduation notice' : 'Graduation reversal notice',
            'banner'   => $approved ? [($student->degree_class ?: 'Graduated'), 'success'] : ['Graduation approval reversed', 'danger'],
            'greeting' => $this->greeting($student),
            'intro'    => [$approved ? 'Your name is on the approved graduation list. Congratulations.' : 'The approval of your graduation has been reversed while it is reviewed. The original approval remains on record.'],
            'details'  => array_filter(['Name' => $student->full_name, 'Matric number' => $student->matric_number, 'Programme' => $student->programme?->name,
                'Class of degree' => $approved ? $student->degree_class : null, 'Final CGPA' => $approved && $student->final_cgpa !== null ? number_format($student->final_cgpa, 2) : null, 'Reason' => $reason]),
            'signatory' => 'registrar',
        ], null, $by);
    }

    public function leaveLetter(\App\Models\LeaveRequest $r, string $kind, ?User $by = null): int
    {
        $doc = \App\Support\LeaveLetters::doc($r, $kind);

        return $this->send('leave_letter', $r, $this->recipients($r->user), $doc['title'] . ' · ' . $r->reference, $doc, str_replace('/', '-', $r->reference) . '-' . $kind . '.html', $by);
    }

    public function payslip(\App\Models\Payslip $slip, ?User $by = null): int
    {
        $slip->loadMissing('run', 'user', 'lines');
        $money = fn ($v) => '₦' . number_format((float) $v, 2);
        $rows = [];
        foreach (['earning' => 'Earnings', 'deduction' => 'Deductions', 'employer' => 'Paid by the university (not deducted)'] as $kind => $heading) {
            $lines = $slip->lines->where('kind', $kind);
            if ($lines->isNotEmpty()) {
                $rows[] = [$heading, ''];
                foreach ($lines as $l) {
                    $rows[] = ['   ' . $l->name, $money($l->amount)];
                }
            }
        }

        return $this->send('payslip', $slip, $this->recipients($slip->user), 'Payslip for ' . $slip->run->label(), [
            'title'     => 'Payslip · ' . $slip->run->label(),
            'banner'    => ['Net pay ' . $money($slip->net), 'success'],
            'greeting'  => 'Dear ' . $slip->user->name . ',',
            'intro'     => ['Your payslip for ' . $slip->run->label() . ' is below. You can also see it, and earlier ones, under My Payslips on the portal.'],
            'details'   => array_filter(['Name' => $slip->name, 'Staff number' => $slip->staff_number, 'Department / unit' => $slip->department,
                'Grade' => $slip->structure ? "{$slip->structure} {$slip->grade_level}/{$slip->step}" : null,
                'Bank' => $slip->bank_name ? $slip->bank_name . ' · ' . substr((string) $slip->bank_account_number, -4) . ' (last four digits)' : null]),
            'table'     => ['head' => ['Item', 'Amount'], 'right' => [1], 'rows' => $rows,
                'foot' => [['Gross pay', $money($slip->gross)], ['Total deductions', $money($slip->total_deductions)], ['Net pay', $money($slip->net)]]],
            'signatory' => 'bursar',
        ], 'payslip-' . $slip->run->period . '.html', $by);
    }

    /** Send again from the email log, rebuilt from the record as it is now. */
    public function resend(DocumentEmail $email, User $by): int
    {
        $class = 'App\\Models\\' . $email->subject_type;
        $subject = class_exists($class) ? $class::find($email->subject_id) : null;
        if (! $subject || ! (self::TYPES[$email->type][2] ?? false)) {
            throw new \RuntimeException('This email cannot be sent again.');
        }

        return match ($email->type) {
            'receipt' => $this->receipt($subject, $by),
            'payment_reversal' => $this->paymentReversed($subject, $by),
            'course_form' => $this->courseForm($subject, $by),
            'clearance_slip' => $this->clearanceSlip($subject, $by),
            'application_slip' => $this->applicationSlip($subject, $by),
            'complaint_response' => $this->complaintAnswered($subject, $by),
            'payslip' => $this->payslip($subject, $by),
        };
    }

    // ---- sending -----------------------------------------------------------------------------------

    /** @return int how many recipients it was sent to */
    public function send(string $type, ?Model $subject, array $to, string $subjectLine, array $doc, ?string $fileName = null, ?User $by = null): int
    {
        if (! self::enabled($type) || ! $to) {
            return 0;
        }
        // Inside a transaction, wait until it commits so nobody is emailed about something that was rolled back.
        \Illuminate\Support\Facades\DB::afterCommit(fn () => $this->deliver($type, $subject, $to, $subjectLine, $doc, $fileName, $by));

        return count($to);
    }

    private function deliver(string $type, ?Model $subject, array $to, string $subjectLine, array $doc, ?string $fileName, ?User $by): void
    {
        $html = view('emails.document', ['doc' => $doc])->render();
        $subjectLine = Str::limit($subjectLine . ' · ' . (Institution::get('short_name') ?: Institution::get('name')), 250, '');
        foreach ($to as $address) {
            $error = null;
            try {
                Mail::to($address)->send(new DocumentMail($subjectLine, $html, $fileName));
            } catch (\Throwable $e) {
                $error = Str::limit($e->getMessage(), 480, '');
                Log::warning("Document email ({$type}) to {$address} failed: {$e->getMessage()}");
            }
            DocumentEmail::create([
                'type' => $type, 'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
                'recipient' => $address, 'subject' => $subjectLine, 'status' => $error ? 'failed' : 'sent', 'error' => $error, 'sent_by' => $by?->id ?? auth()->id(),
            ]);
        }
    }

    private function recipients(?Model $person): array
    {
        $emails = match (true) {
            $person instanceof Student => [$person->user?->email],
            $person instanceof Applicant => [$person->email],
            $person instanceof User => [$person->email],
            default => [],
        };

        return array_values(array_unique(array_filter($emails, fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    private function greeting(?Model $person): ?string
    {
        $first = $person?->first_name ?? null;

        return $first ? "Dear {$first}," : null;
    }

    private function idLabel(?Model $payer): string
    {
        return $payer instanceof Student ? 'Matric number' : 'Application number';
    }
}
