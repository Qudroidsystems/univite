<?php

namespace App\Data\Students;

/** One referee as typed on a form; an empty name means "no referee in this place". */
final readonly class RefereeData
{
    public function __construct(
        public int $position,
        public ?string $name,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $occupation = null,
    ) {
    }

    /** @return self[] from referees[1][name], referees[2][phone] ... */
    public static function listFrom(array $input, int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $r = $input[$i] ?? [];
            $clean = fn ($k) => isset($r[$k]) && trim((string) $r[$k]) !== '' ? trim((string) $r[$k]) : null;
            $out[] = new self($i, $clean('name'), $clean('phone'), $clean('email'), $clean('address'), $clean('occupation'));
        }

        return $out;
    }
}
