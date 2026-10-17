<?php

namespace App\View\Components;

use App\Data\Academics\DepartmentLeaders as Leaders;
use App\Models\Department;
use Illuminate\View\Component;
use Illuminate\View\View;

/** The department, faculty, head of department and dean, shown above a lecturer's results. */
class DepartmentLeaders extends Component
{
    public ?Leaders $leaders;

    public function __construct(?Department $department = null, public string $label = 'Department')
    {
        $this->leaders = Leaders::of($department);
    }

    public function shouldRender(): bool
    {
        return $this->leaders !== null;
    }

    public function render(): View
    {
        return view('components.department-leaders');
    }
}
