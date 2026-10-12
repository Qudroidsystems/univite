<?php

namespace App\Data\Navigation;

/** A collapsible sidebar group of pages. */
final readonly class MenuGroup
{
    /** @param  array<int, MenuLink>  $links */
    public function __construct(public string $id, public string $label, public string $icon, public array $links)
    {
    }

    public function open(): bool
    {
        foreach ($this->links as $link) {
            if ($link->active) {
                return true;
            }
        }

        return false;
    }
}
