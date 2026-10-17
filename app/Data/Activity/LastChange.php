<?php

namespace App\Data\Activity;

use App\Models\RecordActivity;
use Carbon\CarbonInterface;

/** The last person to create or change a record, for a table's "Last changed by" column. */
final readonly class LastChange
{
    public function __construct(
        public string $type,
        public int $id,
        public ?int $userId,
        public string $name,
        public ?string $staffNumber,
        public ?string $photoUrl,
        public string $initials,
        public string $action,
        public CarbonInterface $at,
    ) {
    }

    public static function from(RecordActivity $a, string $type): self
    {
        $u = $a->user;
        $name = $u?->name ?? 'Deleted account';

        return new self($type, (int) $a->subject_id, $u?->id, $name, $u?->staffProfile?->staff_number, $u?->photoUrl(),
            mb_strtoupper(collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')),
            RecordActivity::ACTIONS[$a->action] ?? ucfirst($a->action), $a->created_at);
    }
}
