<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\AssessmentAssignment;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;

/** Which assessment scheme each scope uses: for the session when one is chosen, else the ones for every session. */
class AssessmentsSection implements SetupSection
{
    public function key(): string
    {
        return 'assessments';
    }

    public function label(): string
    {
        return 'Assessment scheme choices';
    }

    public function about(): string
    {
        return 'Which assessment scheme the university, a faculty, department or course uses (for the session, or for every session).';
    }

    public function perSession(): bool
    {
        return false;
    }

    private function sync(?AcademicSession $session): RowSync
    {
        return new RowSync(AssessmentAssignment::class,
            fn () => AssessmentAssignment::query()->when($session, fn ($q) => $q->where('academic_session_id', $session->id), fn ($q) => $q->whereNull('academic_session_id')),
            ['assessment_scheme_id', 'scope_type', 'scope_id', 'academic_session_id', 'assigned_by']);
    }

    public function capture(?AcademicSession $session): array
    {
        return $this->sync($session)->capture();
    }

    public function count(array $data): int
    {
        return count($data);
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        return $this->sync($session)->restore($data, $apply);
    }
}
