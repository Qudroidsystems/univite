<?php

namespace App\Data\Navigation;

/** A titled block of the sidebar (MY PORTAL, ACADEMICS ...): single links and groups. */
final readonly class MenuSection
{
    /** @param  array<int, MenuLink|MenuGroup>  $items */
    public function __construct(public string $title, public array $items)
    {
    }
}
