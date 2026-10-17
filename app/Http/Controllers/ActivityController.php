<?php

namespace App\Http\Controllers;

use App\Services\Activity\RecordActivities;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The details window behind the ⓘ in a table's "Last changed by" column: who that person is, and the record's history. */
class ActivityController extends Controller
{
    public function __construct(private RecordActivities $activities)
    {
    }

    public function show(Request $request, string $type, int $id): View
    {
        abort_unless($this->activities->canView($request->user()), 403);
        $class = RecordActivities::modelClass($type);
        abort_if($class === null, 404);
        $morph = (new $class)->getMorphClass();
        $history = $this->activities->history($morph, $id);
        $staff = $history->first(fn ($a) => $a->action !== 'deleted')?->user ?? $history->first()?->user;

        return view('activity.show', ['history' => $history, 'staff' => $staff, 'type' => class_basename($class), 'id' => $id]);
    }
}
