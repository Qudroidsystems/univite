<?php

namespace App\Data\Lookups;

/** One choice in a dropdown that loads its options when it is opened. */
final readonly class LookupOption
{
    public function __construct(public string $value, public string $label, public ?string $group = null)
    {
    }

    /** @return array{value: string, label: string, group: ?string} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label, 'group' => $this->group];
    }
}
