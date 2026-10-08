<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\ApplicantResult;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** The online application: registering, the checklist an applicant must finish, and submitting. */
class ApplicationService
{
    /** Create the application (not yet submitted) and the screening-fee invoice. */
    public function register(array $data): Applicant
    {
        $session = ApplicationSettings::session() ?? throw new RuntimeException('Applications are closed.');

        return DB::transaction(function () use ($data, $session) {
            $applicant = Applicant::create($data + [
                'academic_session_id' => $session->id,
                'entry_level'         => \App\Models\EntryMode::byCode($data['entry_mode'])?->entry_level ?? 100,
                'status'              => 'draft',
            ]);
            $applicant->update(['application_no' => sprintf('APP/%s/%04d', substr($session->name, 0, 4), $applicant->id)]);

            $fee = ApplicationSettings::fee();
            if ($fee > 0) {
                $invoice = Invoice::create([
                    'kind' => 'screening', 'payable_type' => Applicant::class, 'payable_id' => $applicant->id,
                    'academic_session_id' => $session->id, 'title' => "{$session->name} screening fee",
                    'total' => $fee, 'amount_paid' => 0, 'status' => 'unpaid',
                ]);
                $invoice->update(['reference' => sprintf('INV-%s-%06d', substr($session->name, 0, 4), $invoice->id)]);
                $invoice->lines()->create(['description' => 'Post-UTME screening fee', 'amount' => $fee]);
            }

            return $applicant;
        });
    }

    public function screeningInvoice(Applicant $applicant): ?Invoice
    {
        return $applicant->invoices()->where('status', '!=', 'cancelled')->latest('id')->first();
    }

    /** True when there is no fee to pay, or the screening invoice is settled. */
    public function feePaid(Applicant $applicant): bool
    {
        $invoice = $this->screeningInvoice($applicant);

        return ! $invoice || $invoice->status === 'paid';
    }

    public function isEditable(Applicant $applicant): bool
    {
        return $applicant->status === 'draft';
    }

    /** Documents an applicant must upload: type => label. */
    public function requiredDocuments(Applicant $applicant): array
    {
        $docs = ['passport' => 'Passport photograph', 'olevel' => "O'Level result (scanned)"];
        $needsPrior = \App\Models\EntryMode::byCode($applicant->entry_mode)?->needs_prior_result;
        $docs[$needsPrior ? 'nce_nd' : 'jamb_result'] = $needsPrior ? 'NCE / ND result (scanned)' : 'JAMB result slip';

        return $docs;
    }

    /**
     * What is still missing: a list of [key, label, done]. Submitting requires every item done.
     *
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public function checklist(Applicant $applicant): array
    {
        $applicant->loadMissing('results', 'documents');
        $items = [];

        $items[] = ['fee', 'Pay the screening fee', $this->feePaid($applicant)];
        $items[] = ['profile', 'Complete your personal details and guardian information', $this->profileComplete($applicant)];

        $ssce = $applicant->results->filter->isSsce();
        $credits = $ssce->whereIn('grade', ApplicantResult::CREDIT_GRADES)->unique(fn ($r) => strtolower($r->subject));
        $hasCore = fn (string $needle) => $credits->contains(fn ($r) => str_contains(strtolower($r->subject), $needle));
        $items[] = ['olevel', "Enter your O'Level results: at least 5 credits including English Language and Mathematics", $credits->count() >= 5 && $hasCore('english') && $hasCore('math')];

        if (\App\Models\EntryMode::byCode($applicant->entry_mode)?->needs_prior_result) {
            $items[] = ['nce_nd', 'Enter your NCE or ND result', $applicant->results->contains(fn ($r) => in_array($r->exam_type, ['nce', 'nd']))];
        }

        foreach ($this->requiredDocuments($applicant) as $type => $label) {
            $items[] = ["doc:$type", "Upload: $label", $applicant->documents->contains('type', $type)];
        }

        return $items;
    }

    public function profileComplete(Applicant $applicant): bool
    {
        $required = ['first_name', 'last_name', 'phone_number', 'gender', 'date_of_birth', 'state_of_origin', 'lga', 'nationality', 'address', 'guardian_name', 'guardian_relationship', 'guardian_phone'];
        foreach ($required as $field) {
            if (blank($applicant->{$field})) {
                return false;
            }
        }
        if (\App\Models\EntryMode::byCode($applicant->entry_mode)?->uses_utme && (int) $applicant->jamb_score < ApplicationSettings::minUtmeScore()) {
            return false;
        }

        return true;
    }

    public function submit(Applicant $applicant): void
    {
        if (! $this->isEditable($applicant)) {
            throw new RuntimeException('This application has already been submitted.');
        }

        $missing = collect($this->checklist($applicant))->reject(fn ($item) => $item[2])->pluck(1)->all();
        if ($missing) {
            throw new RuntimeException('You cannot submit yet. Still to do: ' . implode('; ', $missing) . '.');
        }

        $applicant->update(['status' => 'pending', 'submitted_at' => now()]);
    }

    /** Store (or replace) an uploaded document on the private disk. */
    public function storeDocument(Applicant $applicant, string $type, UploadedFile $file): ApplicantDocument
    {
        $path = $file->store("applicants/{$applicant->id}", 'local');

        $existing = $applicant->documents()->where('type', $type)->first();
        if ($existing) {
            Storage::disk('local')->delete($existing->path);
        }

        return $applicant->documents()->updateOrCreate(['type' => $type], [
            'path' => $path, 'original_name' => $file->getClientOriginalName(), 'size' => $file->getSize(),
        ]);
    }

    public function deleteDocument(ApplicantDocument $document): void
    {
        Storage::disk('local')->delete($document->path);
        $document->delete();
    }
}
