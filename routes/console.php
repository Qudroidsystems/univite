<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Leave reminders (approvals waiting, leave starting soon, days left, late resumption) and escalation. Once a day.
Artisan::command('leave:daily', function (\App\Services\LeaveService $leave) {
    $sent = $leave->daily();
    $this->info(collect($sent)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
})->purpose('Send leave reminders, escalate overdue approvals and flag late resumptions');

// Text and WhatsApp messages the provider refused are tried again (three attempts each).
Artisan::command('messages:retry', function (\App\Services\Messenger $messenger) {
    $this->info($messenger->retryFailed() . ' message(s) went through.');
})->purpose('Retry failed text and WhatsApp messages');

\Illuminate\Support\Facades\Schedule::command('leave:daily')->dailyAt('07:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('messages:retry')->everyFifteenMinutes()->withoutOverlapping();
