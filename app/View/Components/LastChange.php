<?php

namespace App\View\Components;

use App\Data\Activity\LastChange as Change;
use App\Services\Activity\RecordActivities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use Illuminate\View\View;

/** A table cell's "Last changed by": the staff member's photo and number, with an ⓘ for their details. */
class LastChange extends Component
{
    public ?Change $change;

    public function __construct(RecordActivities $activities, public Model $record, iterable $among = [])
    {
        $this->change = $activities->lastFor($record, $among);
    }

    public function render(): View
    {
        return view('components.last-change');
    }
}
