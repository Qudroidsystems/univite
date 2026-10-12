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

// Library: reminders before books are due, overdue notices, and copies kept for borrowers who did not collect them.
Artisan::command('library:daily', function (\App\Services\Library\LibraryService $library) {
    $this->info(collect($library->daily())->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
})->purpose('Send library reminders and release uncollected holds');

// Accommodation: beds not paid for by their deadline go back to the pool.
Artisan::command('accommodation:daily', function (\App\Services\Accommodation\AccommodationService $accommodation) {
    $this->info($accommodation->releaseExpired() . ' unpaid reservation(s) released.');
})->purpose('Release hostel beds not paid for in time');

// Online payments: check payments still waiting with their gateway, and close ones never finished.
Artisan::command('payments:reconcile', function (\App\Services\Payments\OnlinePaymentService $payments) {
    $this->info($payments->reconcile()->message());
})->purpose('Confirm online payments still waiting with their gateway');

\Illuminate\Support\Facades\Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('leave:daily')->dailyAt('07:00')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('messages:retry')->everyFifteenMinutes()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('library:daily')->dailyAt('07:30')->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('accommodation:daily')->dailyAt('06:30')->withoutOverlapping();
