<?php

namespace App\Data\Students;

/** Move a student to another programme (and option, and level). */
final readonly class TransferRequest
{
    public function __construct(
        public int $programmeId,
        public int $level,
        public string $reason,
        public ?int $courseOptionId = null,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self((int) $a['programme_id'], (int) $a['level'], trim((string) $a['reason']), isset($a['course_option_id']) && $a['course_option_id'] !== '' ? (int) $a['course_option_id'] : null);
    }
}
