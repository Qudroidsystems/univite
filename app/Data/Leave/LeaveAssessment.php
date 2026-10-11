<?php

namespace App\Data\Leave;

/** What is wrong with a leave application (errors stop it) and what an approver should know (warnings). */
final readonly class LeaveAssessment
{
    /**
     * @param  string[]  $errors
     * @param  string[]  $warnings
     */
    public function __construct(public int $days, public array $errors = [], public array $warnings = [])
    {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function toArray(): array
    {
        return ['errors' => $this->errors, 'warnings' => $this->warnings, 'days' => $this->days];
    }
}
