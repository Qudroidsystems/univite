<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
 * Mobile app API, version 1. Every route answers JSON as {"data": ..., "meta": ...}.
 * Sign in with POST /api/v1/auth/login, then send "Authorization: Bearer <token>" on every other call.
 * See README "Mobile app API" for the list.
 */
Route::prefix('v1')->middleware(\App\Http\Middleware\ApiJson::class)->name('api.')->group(function () {
    Route::get('ping', fn () => response()->json(['data' => ['ok' => true, 'app' => config('app.name'), 'time' => now()->toIso8601String(),
        // The school's name, logo and colour, so the app can brand itself before anyone signs in.
        'institution' => [
            'name' => \App\Support\Institution::get('name') ?: config('app.name'), 'short_name' => \App\Support\Institution::get('short_name'),
            'motto' => \App\Support\Institution::get('motto'), 'logo_url' => \App\Support\Institution::image('logo'),
            'color' => \App\Support\Institution::get('primary_color', '#405189'),
        ],
        // Feature switches, the certificate setting and the like, from Settings -> Mobile App.
        'mobile' => app(\App\Support\MobileAppSettings::class)->config(),
    ]]))->name('ping');
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

        Route::get('teaching/courses', [V1\TeachingController::class, 'courses'])->name('teaching.courses');
        Route::post('teaching/courses/{course}/open', [V1\TeachingController::class, 'open'])->name('teaching.open');
        Route::get('teaching/sheets/{sheet}', [V1\TeachingController::class, 'show'])->name('teaching.sheets.show');
        Route::put('teaching/sheets/{sheet}/scores', [V1\TeachingController::class, 'save'])->name('teaching.sheets.save');
        Route::post('teaching/sheets/{sheet}/submit', [V1\TeachingController::class, 'submit'])->name('teaching.sheets.submit');

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

        // ---- Doing things in the app (instead of opening the portal) ----
        $a = V1\ActionsController::class;
        Route::get('registration', [$a, 'registration'])->name('registration');
        Route::post('registration', [$a, 'saveRegistration'])->name('registration.save');
        Route::get('fees/{invoice}/pay', [$a, 'payOptions'])->name('fees.pay.options');
        Route::post('fees/{invoice}/pay', [$a, 'pay'])->middleware('throttle:10,1')->name('fees.pay');
        Route::post('payments/{reference}/confirm', [$a, 'confirmPayment'])->middleware('throttle:30,1')->name('payments.confirm');
        Route::get('results/queries', [$a, 'queries'])->name('results.queries');
        Route::post('results/{result}/query', [$a, 'query'])->name('results.query');
        Route::get('accommodation/options', [$a, 'hostelOptions'])->name('accommodation.options');
        Route::post('accommodation/apply', [$a, 'applyHostel'])->name('accommodation.apply');
        Route::post('accommodation/withdraw', [$a, 'withdrawHostel'])->name('accommodation.withdraw');
        Route::get('library/search', [$a, 'librarySearch'])->name('library.search');
        Route::post('library/loans/{loan}/renew', [$a, 'renew'])->name('library.renew');
        Route::post('library/items/{item}/hold', [$a, 'hold'])->name('library.hold');
        Route::post('library/holds/{hold}/cancel', [$a, 'cancelHold'])->name('library.hold.cancel');
        Route::post('clearance/{item}/resubmit', [$a, 'resubmitClearance'])->name('clearance.resubmit');
        Route::get('id-card', [$a, 'idCard'])->name('id-card');
        Route::post('id-card', [$a, 'requestIdCard'])->name('id-card.request');
        Route::get('polls', [$a, 'polls'])->name('polls');
        Route::get('polls/{poll}', [$a, 'poll'])->name('polls.show');
        Route::post('polls/{poll}/vote', [$a, 'vote'])->middleware('throttle:20,1')->name('polls.vote');
        Route::put('me/password', [$a, 'password'])->middleware('throttle:10,1')->name('me.password');

        // ---- Campus life ----
        $c = V1\CampusLifeController::class;
        Route::get('me/profile', [$c, 'myProfile'])->name('me.profile');
        Route::put('me/profile', [$c, 'updateProfile'])->name('me.profile.update');
        Route::post('me/photo', [$c, 'photo'])->name('me.photo');
        Route::delete('me/photo', [$c, 'removePhoto'])->name('me.photo.remove');

        Route::get('chats', [$c, 'chats'])->name('chats');
        Route::post('chats/direct', [$c, 'direct'])->name('chats.direct');
        Route::post('chats/groups', [$c, 'createGroup'])->middleware('throttle:20,1')->name('chats.groups');
        Route::get('chats/{conversation}', [$c, 'chat'])->name('chats.show');
        Route::put('chats/{conversation}', [$c, 'updateChat'])->name('chats.update');
        Route::get('chats/{conversation}/messages', [$c, 'messages'])->name('chats.messages');
        Route::post('chats/{conversation}/messages', [$c, 'send'])->middleware('throttle:60,1')->name('chats.send');
        Route::post('chats/{conversation}/read', [$c, 'read'])->name('chats.read');
        Route::get('chats/{conversation}/members', [$c, 'members'])->name('chats.members');
        Route::post('chats/{conversation}/members', [$c, 'addMembers'])->name('chats.members.add');
        Route::post('chats/{conversation}/join', [$c, 'join'])->name('chats.join');
        Route::post('chats/{conversation}/leave', [$c, 'leave'])->name('chats.leave');
        Route::delete('chat/messages/{message}', [$c, 'deleteMessage'])->name('chat.messages.delete');
        Route::get('chat/attachments/{message}', [$c, 'attachment'])->name('chat.attachment');
        Route::post('realtime/auth', [$c, 'realtimeAuth'])->name('realtime.auth');

        Route::get('market', [$c, 'listings'])->name('market');
        Route::get('market/mine', [$c, 'myListings'])->name('market.mine');
        Route::post('market', [$c, 'createListing'])->middleware('throttle:20,1')->name('market.store');
        Route::get('market/{listing}', [$c, 'listing'])->name('market.show');
        Route::post('market/{listing}', [$c, 'updateListing'])->name('market.update');
        Route::delete('market/{listing}', [$c, 'deleteListing'])->name('market.delete');
        Route::post('market/{listing}/status', [$c, 'listingStatus'])->name('market.status');
        Route::post('market/{listing}/save', [$c, 'saveListing'])->name('market.save');
        Route::post('market/{listing}/contact', [$c, 'contact'])->name('market.contact');

        Route::get('feed', [$c, 'feedIndex'])->name('feed');
        Route::post('feed', [$c, 'createPost'])->middleware('throttle:20,1')->name('feed.store');
        Route::get('feed/{post}', [$c, 'post'])->name('feed.show');
        Route::delete('feed/{post}', [$c, 'deletePost'])->name('feed.delete');
        Route::post('feed/{post}/like', [$c, 'like'])->name('feed.like');
        Route::post('feed/{post}/comments', [$c, 'comment'])->middleware('throttle:30,1')->name('feed.comment');
        Route::delete('feed/comments/{comment}', [$c, 'deleteComment'])->name('feed.comment.delete');
        Route::post('feed/{post}/rsvp', [$c, 'rsvp'])->name('feed.rsvp');

        Route::get('materials', [$c, 'materialCourses'])->name('materials');
        Route::get('materials/courses/{course}', [$c, 'courseMaterials'])->name('materials.course');
        Route::post('materials/courses/{course}', [$c, 'addMaterial'])->name('materials.store');
        Route::get('materials/{material}/download', [$c, 'downloadMaterial'])->name('materials.download');
        Route::delete('materials/{material}', [$c, 'deleteMaterial'])->name('materials.delete');

        Route::get('people', [$c, 'people'])->name('people');
        Route::get('people/{user}', [$c, 'person'])->name('people.show');
        Route::post('people/{user}/block', [$c, 'block'])->name('people.block');
        Route::get('reports/reasons', [$c, 'reportReasons'])->name('reports.reasons');
        Route::post('reports', [$c, 'report'])->middleware('throttle:20,1')->name('reports.store');

        Route::post('devices', [V1\DevicesController::class, 'store'])->name('devices.store');
        Route::delete('devices', [V1\DevicesController::class, 'destroy'])->name('devices.destroy');
    });
});
