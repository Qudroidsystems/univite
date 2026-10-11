<?php

namespace App\Data\Fees;

use App\Models\AcademicSession;

/** Which students a bulk invoice run covers: everyone active in the session, optionally narrowed. */
final readonly class InvoiceTarget
{
    public function __construct(
        public AcademicSession $session,
        public ?int $programmeId = null,
        public ?int $level = null,
        public ?int $semesterTypeId = null,
    ) {
    }

    /** @param  array{academic_session_id: int|string, programme_id?: int|string|null, level?: int|string|null, semester_type_id?: int|string|null}  $a */
    public static function fromArray(array $a): self
    {
        $int = fn ($k) => isset($a[$k]) && $a[$k] !== '' ? (int) $a[$k] : null;

        return new self(AcademicSession::findOrFail($a['academic_session_id']), $int('programme_id'), $int('level'), $int('semester_type_id'));
    }

    public function filters(): array
    {
        return array_filter(['programme_id' => $this->programmeId, 'level' => $this->level, 'semester_type_id' => $this->semesterTypeId]);
    }
}
