<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ImplicitRule;

/**
 * A closure rule that runs even when the field is empty or missing (Laravel skips ordinary closure rules then).
 * The closure gets ($attribute, $value, $fail) like any closure rule.
 */
class AlwaysCheck implements ImplicitRule
{
    private ?string $message = null;

    public function __construct(private Closure $check)
    {
    }

    public function passes($attribute, $value): bool
    {
        $this->message = null;
        ($this->check)($attribute, $value, function (string $m) {
            $this->message ??= $m;
        });

        return $this->message === null;
    }

    public function message(): string
    {
        return (string) $this->message;
    }
}
