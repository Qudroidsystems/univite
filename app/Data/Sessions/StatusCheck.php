<?php

namespace App\Data\Sessions;

/** Before a session changes status: what stops the change, what deserves a look, and whether a reason is needed. */
final readonly class StatusCheck
{
    /**
     * @param  string[]  $problems
     * @param  string[]  $warnings
     */
    public function __construct(public array $problems = [], public array $warnings = [], public bool $needsReason = false)
    {
    }

    public function allowed(): bool
    {
        return $this->problems === [];
    }

    public function clean(): bool
    {
        return $this->problems === [] && $this->warnings === [];
    }
}
