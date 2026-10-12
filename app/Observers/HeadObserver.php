<?php

namespace App\Observers;

use App\Services\Access\HeadRoles;
use Illuminate\Database\Eloquent\Model;

/** Keeps the heads' scoped roles in step when a department, faculty or unit gets a new head or is removed. */
class HeadObserver
{
    public function __construct(private HeadRoles $heads)
    {
    }

    public function saved(Model $m): void
    {
        [, $column] = HeadRoles::HEADS[HeadRoles::typeOf($m)];
        if ($m->wasRecentlyCreated || $m->wasChanged($column)) {
            $this->heads->sync($m);
        }
    }

    public function deleted(Model $m): void
    {
        $this->heads->forgetScope($m);
    }
}
