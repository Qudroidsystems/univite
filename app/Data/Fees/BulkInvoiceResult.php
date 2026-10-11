<?php

namespace App\Data\Fees;

/** What a bulk invoice run did. The operation id points to its entry in the operation history. */
final readonly class BulkInvoiceResult
{
    public function __construct(public int $created, public int $existing, public int $noBills, public ?string $operation)
    {
    }

    public function message(): string
    {
        if (! $this->created && ! $this->existing && ! $this->noBills) {
            return 'There are no active students to invoice.';
        }
        $m = "{$this->created} invoice(s) created" . ($this->created ? " (operation {$this->operation}; it can be reversed from the operation history while nothing has been paid)" : '') . '.';
        if ($this->existing) {
            $m .= " {$this->existing} student(s) already had one.";
        }
        if ($this->noBills) {
            $m .= " {$this->noBills} student(s) skipped because no school bills match them (programme, level, nationality, fresh or returning) in that session.";
        }

        return $m;
    }

    public function succeeded(): bool
    {
        return $this->created > 0 || $this->existing > 0;
    }
}
