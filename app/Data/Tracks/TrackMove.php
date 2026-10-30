<?php

namespace App\Data\Tracks;

/** What a move does to one student: where they go, or why they stay. */
final readonly class TrackMove
{
    public function __construct(
        public int $studentId,
        public string $name,
        public string $matric,
        public string $programme,
        public int $fromLevel,
        public ?int $toLevel,
        public ?string $remark,
        public ?string $action,
        public ?int $computedResultId,
        public ?string $held = null,
    ) {
    }

    public function moves(): bool
    {
        return $this->held === null;
    }
}
