<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\ResultSheet;
use App\Models\ResultStage;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;

/** The result approval steps. A step added since that a sheet is waiting at is switched off rather than removed. */
class ApprovalStepsSection implements SetupSection
{
    public function key(): string
    {
        return 'approval';
    }

    public function label(): string
    {
        return 'Result approval steps';
    }

    public function about(): string
    {
        return 'The steps a score sheet passes through before publication, in order, with the office that signs each.';
    }

    public function perSession(): bool
    {
        return false;
    }

    private function sync(): RowSync
    {
        return new RowSync(ResultStage::class, fn () => ResultStage::query(), ['name', 'office_role', 'sort_order', 'is_active'],
            function (ResultStage $stage, bool $apply) {
                if (! ResultSheet::where('stage_id', $stage->id)->exists()) {
                    return null;
                }
                $apply && $stage->update(['is_active' => false]);

                return "Score sheets are waiting at \"{$stage->name}\", so it was switched off instead of removed.";
            });
    }

    public function capture(?AcademicSession $session): array
    {
        return $this->sync()->capture();
    }

    public function count(array $data): int
    {
        return count($data);
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        return $this->sync()->restore($data, $apply);
    }
}
