<?php

namespace App\View\Components;

use App\Data\Fees\FeeStanding;
use App\Models\Student;
use App\Services\Fees\FeeAccess;
use Illuminate\View\Component;
use Illuminate\View\View;

/** Reminds a student what they still owe, where the school or their department asks for it. Shows nothing otherwise. */
class OwingNotice extends Component
{
    public ?FeeStanding $standing;

    public function __construct(FeeAccess $fees, public ?Student $student = null)
    {
        $this->student ??= auth()->user()?->student;
        $this->standing = $this->student ? $fees->standing($this->student) : null;
    }

    public function shouldRender(): bool
    {
        return (bool) $this->standing?->remindOwing();
    }

    public function render(): View
    {
        return view('components.owing-notice');
    }
}
