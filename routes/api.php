<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
 * Mobile app API, version 1. Every route answers JSON as {"data": ..., "meta": ...}.
 * Sign in with POST /api/v1/auth/login, then send "Authorization: Bearer <token>" on every other call.
 * See README "Mobile app API" for the list.
 */
Route::prefix('v1')->middleware(\App\Http\Middleware\ApiJson::class)->name('api.')->group(function () {
    Route::get('ping', fn () => response()->json(['data' => ['ok' => true, 'app' => config('app.name'), 'time' => now()->toIso8601String()]]))->name('ping');
    Route::post('auth/login', [V1\AuthController::class, 'login'])->middleware('throttle:mobile-login')->name('login');

    Route::middleware(['auth:sanctum', 'throttle:mobile-api'])->group(function () {
        Route::post('auth/logout', [V1\AuthController::class, 'logout'])->name('logout');
        Route::get('me', [V1\AuthController::class, 'me'])->name('me');
        Route::get('dashboard', V1\DashboardController::class)->name('dashboard');

        Route::get('results', [V1\AcademicsController::class, 'results'])->name('results');
        Route::get('courses', [V1\AcademicsController::class, 'courses'])->name('courses');
        Route::get('timetable/lectures', [V1\AcademicsController::class, 'lectures'])->name('timetable.lectures');
        Route::get('timetable/exams', [V1\AcademicsController::class, 'exams'])->name('timetable.exams');
        Route::get('timetable/teaching', [V1\AcademicsController::class, 'teaching'])->name('timetable.teaching');

        Route::get('fees', [V1\FeesController::class, 'index'])->name('fees');
        Route::get('fees/{invoice}', [V1\FeesController::class, 'show'])->name('fees.show');

        Route::get('clearance', [V1\CampusController::class, 'clearance'])->name('clearance');
        Route::get('library', [V1\CampusController::class, 'library'])->name('library');
        Route::get('accommodation', [V1\CampusController::class, 'accommodation'])->name('accommodation');

        Route::get('notifications', [V1\NotificationsController::class, 'index'])->name('notifications');
        Route::get('notifications/unread-count', [V1\NotificationsController::class, 'unreadCount'])->name('notifications.unread');
        Route::post('notifications/read-all', [V1\NotificationsController::class, 'readAll'])->name('notifications.read-all');
        Route::get('notifications/settings', [V1\NotificationsController::class, 'settings'])->name('notifications.settings');
        Route::put('notifications/settings', [V1\NotificationsController::class, 'updateSettings'])->name('notifications.settings.update');
        Route::post('notifications/{id}/read', [V1\NotificationsController::class, 'read'])->name('notifications.read');

        Route::post('devices', [V1\DevicesController::class, 'store'])->name('devices.store');
        Route::delete('devices', [V1\DevicesController::class, 'destroy'])->name('devices.destroy');
    });
});
