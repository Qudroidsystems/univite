<?php

namespace App\View\Composers;

use App\Support\Navigation\Menu;
use Illuminate\View\View;

/** Gives the signed-in layout its sidebar menu and the list of pages the search box can jump to. */
class ShellComposer
{
    public function __construct(private Menu $menu)
    {
    }

    public function compose(View $view): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $sections = $this->menu->for($user);
        $view->with(['menuSections' => $sections, 'searchPages' => $this->menu->pages($sections)]);
    }
}
