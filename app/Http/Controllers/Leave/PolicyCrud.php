<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Academics\CrudController;
use Illuminate\Http\Request;

/** A setup page guarded by one permission for viewing and changing, instead of View/Create/Edit/Delete {noun}. */
abstract class PolicyCrud extends CrudController
{
    /** The permission that allows everything on the page. */
    protected string $gate = 'Set leave policy';

    /** Extra permissions that may only look. */
    protected array $viewers = [];

    public function __construct()
    {
        $this->middleware('permission:' . implode('|', array_merge([$this->gate], $this->viewers)))->only('index');
        $this->middleware('permission:' . $this->gate)->only('store', 'update', 'destroy');
    }

    protected function viewData(Request $request): array
    {
        $can = $request->user()->can($this->gate);

        return ['abilities' => ['create' => $can, 'edit' => $can, 'delete' => $can]];
    }
}
