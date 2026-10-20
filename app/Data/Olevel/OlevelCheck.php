<?php

namespace App\Data\Olevel;

use App\Models\OlevelRequirement;

/**
 * A student's O Level results measured against their department's (or programme's) requirement.
 *
 * @property-read array<string, string> $grades  best confirmed grade per subject (subject => grade) over the sittings used
 */
final readonly class OlevelCheck
{
    /**
     * @param  string[]  $problems  what is missing (empty = requirement met)
     * @param  array<string, string>  $grades
     * @param  string[]  $sittings  labels of the sittings the best result used
     */
    public function __construct(
        public ?OlevelRequirement $requirement,
        public bool $applies,
        public bool $met,
        public array $problems = [],
        public array $grades = [],
        public array $sittings = [],
        public int $credits = 0,
        public bool $overridden = false,
        public bool $hasConfirmed = false,
        public bool $awaiting = false,
    ) {
    }

    /** Whether course registration may go ahead as far as O Level is concerned. */
    public function allowsRegistration(): bool
    {
        return ! $this->applies || $this->met || $this->overridden;
    }

    public function status(): string
    {
        return match (true) {
            ! $this->requirement => 'No O Level requirement is set for this department.',
            $this->met => 'Meets the O Level requirement.',
            $this->overridden => 'Allowed to register by the school while the O Level is sorted out.',
            $this->awaiting => 'Waiting for the school to confirm the O Level results.',
            ! $this->hasConfirmed => 'No O Level result confirmed yet.',
            default => 'Does not meet the O Level requirement.',
        };
    }
}
