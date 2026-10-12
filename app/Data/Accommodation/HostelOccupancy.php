<?php

namespace App\Data\Accommodation;

use App\Models\Hostel;

final readonly class HostelOccupancy
{
    public function __construct(public Hostel $hostel, public int $beds, public int $reserved, public int $confirmed, public int $checkedIn, public float $rate)
    {
    }

    public function free(): int
    {
        return max(0, $this->beds - $this->reserved - $this->confirmed - $this->checkedIn);
    }
}
