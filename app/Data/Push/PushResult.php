<?php

namespace App\Data\Push;

/** What happened to one push: sent, logged (no provider set up), failed (try again later) or invalid (the app was removed). */
final readonly class PushResult
{
    public function __construct(public string $status, public ?string $reference = null, public ?string $error = null)
    {
    }

    public static function logged(): self
    {
        return new self('logged');
    }

    public static function invalid(string $error): self
    {
        return new self('invalid', null, $error);
    }

    public static function failed(string $error): self
    {
        return new self('failed', null, $error);
    }
}
