<?php

namespace App\Data\Results;

use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\SemesterType;
use Illuminate\Http\Request;

/** Which master sheet to show: programme, level, session and semester, and whether to include unpublished results. */
final readonly class MasterSheetQuery
{
    public function __construct(
        public ?Programme $programme,
        public ?int $level,
        public ?AcademicSession $session,
        public ?SemesterType $semesterType,
        public bool $provisional,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $session = $request->filled('session_id') ? AcademicSession::find((int) $request->input('session_id')) : AcademicSession::current();
        $type = $request->filled('semester_type_id') ? SemesterType::find((int) $request->input('semester_type_id')) : SemesterType::orderBy('sort_order')->first();

        return new self(
            $request->filled('programme_id') ? Programme::find((int) $request->input('programme_id')) : null,
            $request->filled('level') ? (int) $request->input('level') : null,
            $session,
            $type,
            $request->input('published_only') ? false : true,
        );
    }

    public function complete(): bool
    {
        return $this->programme && $this->level && $this->session && $this->semesterType;
    }

    /** @return array<string, mixed> the query string that shows this sheet again */
    public function params(): array
    {
        return array_filter([
            'programme_id' => $this->programme?->id, 'level' => $this->level, 'session_id' => $this->session?->id,
            'semester_type_id' => $this->semesterType?->id, 'published_only' => $this->provisional ? null : 1,
        ], fn ($v) => $v !== null);
    }
}
