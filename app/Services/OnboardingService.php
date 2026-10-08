<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * What a freshly admitted student must do before the university verifies them in person:
 * accept the offer, pay the acceptance fee, complete their biodata and upload the remaining documents.
 */
class OnboardingService
{
    /** Extra documents asked of admitted students, on top of what they uploaded when applying. */
    public const EXTRA_DOCUMENTS = ['birth_certificate', 'state_of_origin'];

    public function __construct(private InvoiceService $invoices, private ApplicationService $applications)
    {
    }

    public function acceptOffer(Student $student): void
    {
        if (! $student->needs_onboarding) {
            throw new RuntimeException('This student does not need to accept an offer.');
        }
        if (! in_array($student->status, \App\Models\StudentStatus::codesWhere('is_enrolled'), true)) {
            throw new RuntimeException('Your record is not active, so the offer cannot be accepted. Please contact the admissions office.');
        }
        if ($student->offer_accepted_at) {
            return;
        }

        $student->update(['offer_accepted_at' => now()]);
        $this->invoices->generateAcceptanceInvoice($student);
    }

    public function acceptanceInvoice(Student $student): ?Invoice
    {
        return $student->invoices()->where('kind', 'acceptance')->where('status', '!=', 'cancelled')->latest('id')->first();
    }

    public function isLocked(Student $student): bool
    {
        return $student->verified_at !== null;
    }

    public function biodataComplete(Student $student): bool
    {
        foreach (['phone_number', 'gender', 'date_of_birth', 'state_of_origin', 'lga', 'nationality', 'address', 'guardian_name', 'guardian_relationship', 'guardian_phone'] as $field) {
            if (blank($student->{$field})) {
                return false;
            }
        }

        return true;
    }

    /** Documents the student must have on file: [type => label]. */
    public function requiredDocuments(Student $student): array
    {
        $labels = ApplicantDocument::TYPES;
        $types = array_keys($student->applicant ? $this->applications->requiredDocuments($student->applicant) : ['passport' => 1]);

        return collect(array_merge($types, self::EXTRA_DOCUMENTS))->unique()->mapWithKeys(fn ($t) => [$t => $labels[$t]])->all();
    }

    /** The documents on file, keyed by type. */
    public function documents(Student $student)
    {
        return $student->applicant ? $student->applicant->documents->keyBy('type') : collect();
    }

    /** @return array<int, array{0: string, 1: string, 2: bool}> [key, label, done] */
    public function checklist(Student $student): array
    {
        $student->loadMissing('applicant.documents');
        $items = [];

        $items[] = ['offer', 'Accept your admission offer', $student->offer_accepted_at !== null];

        $invoice = $this->acceptanceInvoice($student);
        // The fee step shows once an invoice exists, or beforehand when a fee is configured (it is raised on acceptance).
        if ($invoice) {
            $items[] = ['fee', 'Pay the acceptance fee', $invoice->status === 'paid'];
        } elseif (! $student->offer_accepted_at && ApplicationSettings::acceptanceItemId()) {
            $items[] = ['fee', 'Pay the acceptance fee', false];
        }

        $items[] = ['biodata', 'Complete your biodata', $this->biodataComplete($student)];

        $docs = $this->documents($student);
        foreach ($this->requiredDocuments($student) as $type => $label) {
            $items[] = ["doc:$type", "Upload: $label", $docs->has($type)];
        }

        $items[] = ['verified', 'Attend physical verification at the university', $student->verified_at !== null];

        return $items;
    }

    /** Everything the student can do online is done (only physical verification may remain). */
    public function readyForVerification(Student $student): bool
    {
        return collect($this->checklist($student))->reject(fn ($i) => $i[0] === 'verified')->every(fn ($i) => $i[2]);
    }

    /** awaiting (student still has to act) | ready (waiting for the verification officer) | verified */
    public function state(Student $student): string
    {
        return $student->verified_at ? 'verified' : ($this->readyForVerification($student) ? 'ready' : 'awaiting');
    }

    public function storeDocument(Student $student, string $type, UploadedFile $file): ApplicantDocument
    {
        if ($this->isLocked($student)) {
            throw new RuntimeException('You have been verified, so your documents can no longer be changed.');
        }
        $applicant = $student->applicant ?? throw new RuntimeException('There is no application on file for this student.');

        return $this->applications->storeDocument($applicant, $type, $file);
    }

    public function verify(Student $student, int $userId, ?string $note = null): void
    {
        if (! $student->needs_onboarding) {
            throw new RuntimeException('This student does not go through verification.');
        }
        if (! $this->readyForVerification($student)) {
            $missing = collect($this->checklist($student))->reject(fn ($i) => $i[2] || $i[0] === 'verified')->pluck(1)->all();
            throw new RuntimeException('The student has not finished: ' . implode('; ', $missing) . '.');
        }

        $student->update(['verified_at' => now(), 'verified_by' => $userId, 'verification_note' => $note]);
    }

    public function unverify(Student $student): void
    {
        $student->update(['verified_at' => null, 'verified_by' => null, 'verification_note' => null]);
    }
}
