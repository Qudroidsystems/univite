<?php

namespace App\Data\Lookups;

use Illuminate\Http\Request;

/** What a dropdown asked for: the parent it depends on (a faculty for departments ...), active rows only, and search text. */
final readonly class LookupQuery
{
    public function __construct(public ?int $parent = null, public bool $activeOnly = false, public ?string $term = null)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $parent = $request->query('parent');
        $term = trim((string) $request->query('q', ''));

        return new self(
            is_numeric($parent) ? (int) $parent : null,
            $request->boolean('active'),
            $term === '' ? null : mb_substr($term, 0, 80),
        );
    }
}
