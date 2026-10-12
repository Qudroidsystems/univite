<?php

namespace App\Data\Navigation;

/** One page in the sidebar (and in the search box's list of pages). */
final readonly class MenuLink
{
    /** @param  array<int, string>  $keywords  other words people may type to find this page */
    public function __construct(
        public string $label,
        public string $url,
        public string $icon,
        public bool $active = false,
        public ?int $badge = null,
        public array $keywords = [],
    ) {
    }
}
