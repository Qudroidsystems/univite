<?php

namespace App\Jobs;

use App\Models\TimetableRun;
use App\Services\Timetable\TimetableRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Generate one timetable candidate in the background. */
class RunTimetableGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
    }

    public function handle(TimetableRuns $runs): void
    {
        if ($run = TimetableRun::find($this->runId)) {
            $runs->execute($run);
        }
    }
}
