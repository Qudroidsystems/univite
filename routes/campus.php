<?php

/*
|--------------------------------------------------------------------------
| Campus services: polls and elections, identity cards, library, health, accommodation and teaching practice.
| Loaded from web.php.
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Accommodation\AllocationController;
use App\Http\Controllers\Accommodation\HostelController;
use App\Http\Controllers\Accommodation\MyAccommodationController;
use App\Http\Controllers\Health\ClinicController;
use App\Http\Controllers\Health\ExcuseCheckController;
use App\Http\Controllers\Health\MyHealthController;
use App\Http\Controllers\IdCards\CardCheckController;
use App\Http\Controllers\IdCards\IdCardController;
use App\Http\Controllers\IdCards\MyIdCardController;
use App\Http\Controllers\Library\CatalogueController;
use App\Http\Controllers\Library\DeskController;
use App\Http\Controllers\Library\MyLibraryController;
use App\Http\Controllers\Polls\MyPollController;
use App\Http\Controllers\TeachingPractice\ExerciseController;
use App\Http\Controllers\TeachingPractice\MyTeachingPracticeController;
use App\Http\Controllers\TeachingPractice\SchoolController as TpSchoolController;
use App\Http\Controllers\TeachingPractice\SupervisionController;
use App\Http\Controllers\Polls\PollController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // ---- Polls and elections ----
    Route::get('polls/{poll}/export', [PollController::class, 'export'])->name('polls.export');
    Route::post('polls/{poll}/publish', [PollController::class, 'publish'])->name('polls.publish');
    Route::post('polls/{poll}/unpublish', [PollController::class, 'unpublish'])->name('polls.unpublish');
    Route::post('polls/{poll}/close', [PollController::class, 'close'])->name('polls.close');
    Route::post('polls/{poll}/results', [PollController::class, 'publishResults'])->name('polls.results.publish');
    Route::post('polls/{poll}/questions', [PollController::class, 'storeQuestion'])->name('polls.questions.store');
    Route::put('poll-questions/{question}', [PollController::class, 'updateQuestion'])->name('polls.questions.update');
    Route::delete('poll-questions/{question}', [PollController::class, 'destroyQuestion'])->name('polls.questions.destroy');
    Route::post('poll-questions/{question}/options', [PollController::class, 'storeOption'])->name('polls.options.store');
    Route::delete('poll-options/{option}', [PollController::class, 'destroyOption'])->name('polls.options.destroy');
    Route::resource('polls', PollController::class)->except([]);
    Route::get('my/polls', [MyPollController::class, 'index'])->name('my.polls');
    Route::get('my/polls/{poll}', [MyPollController::class, 'show'])->name('my.polls.show');
    Route::post('my/polls/{poll}', [MyPollController::class, 'vote'])->middleware('throttle:20,1')->name('my.polls.vote');

    // ---- Identity cards ----
    Route::get('id-cards', [IdCardController::class, 'index'])->name('id-cards.index');
    Route::post('id-cards/issue', [IdCardController::class, 'issue'])->name('id-cards.issue');
    Route::post('id-cards/batch', [IdCardController::class, 'batch'])->name('id-cards.batch');
    Route::get('id-cards/print', [IdCardController::class, 'print'])->name('id-cards.print');
    Route::post('id-cards/printed', [IdCardController::class, 'printed'])->name('id-cards.printed');
    Route::get('id-cards/settings', [IdCardController::class, 'settings'])->name('id-cards.settings');
    Route::put('id-cards/settings', [IdCardController::class, 'saveSettings'])->name('id-cards.settings.save');
    Route::post('id-cards/photo/{user}', [IdCardController::class, 'photo'])->name('id-cards.photo');
    Route::post('id-cards/{card}/approve', [IdCardController::class, 'approve'])->name('id-cards.approve');
    Route::post('id-cards/{card}/collected', [IdCardController::class, 'collected'])->name('id-cards.collected');
    Route::post('id-cards/{card}/cancel', [IdCardController::class, 'cancel'])->name('id-cards.cancel');
    Route::get('my/id-card', [MyIdCardController::class, 'show'])->name('my.id-card');
    Route::post('my/id-card', [MyIdCardController::class, 'request'])->name('my.id-card.request');
    Route::post('my/id-card/withdraw', [MyIdCardController::class, 'withdraw'])->name('my.id-card.withdraw');

    // ---- Library ----
    Route::get('library/import', [CatalogueController::class, 'importForm'])->name('library.import');
    Route::post('library/import', [CatalogueController::class, 'import'])->name('library.import.store');
    Route::post('library/items/{item}/copies', [CatalogueController::class, 'addCopies'])->name('library.copies.store');
    Route::put('library/copies/{copy}', [CatalogueController::class, 'updateCopy'])->name('library.copies.update');
    Route::resource('library/items', CatalogueController::class)->names('library.items')->parameters(['items' => 'item']);
    Route::get('library/desk', [DeskController::class, 'desk'])->name('library.desk');
    Route::post('library/desk/issue', [DeskController::class, 'issue'])->name('library.issue');
    Route::post('library/desk/return', [DeskController::class, 'giveBack'])->name('library.return');
    Route::post('library/loans/{loan}/renew', [DeskController::class, 'renew'])->name('library.loans.renew');
    Route::post('library/loans/{loan}/lost', [DeskController::class, 'lost'])->name('library.loans.lost');
    Route::get('library/loans', [DeskController::class, 'loans'])->name('library.loans');
    Route::get('library/fines', [DeskController::class, 'fines'])->name('library.fines');
    Route::post('library/fines/{loan}/paid', [DeskController::class, 'finePaid'])->name('library.fines.paid');
    Route::post('library/fines/{loan}/waive', [DeskController::class, 'waive'])->name('library.fines.waive');
    Route::get('library/settings', [DeskController::class, 'settings'])->name('library.settings');
    Route::put('library/settings', [DeskController::class, 'saveSettings'])->name('library.settings.save');
    Route::get('my/library', [MyLibraryController::class, 'index'])->name('my.library');
    Route::post('my/library/loans/{loan}/renew', [MyLibraryController::class, 'renew'])->name('my.library.renew');
    Route::post('my/library/items/{item}/hold', [MyLibraryController::class, 'hold'])->name('my.library.hold');
    Route::post('my/library/holds/{hold}/cancel', [MyLibraryController::class, 'cancelHold'])->name('my.library.hold.cancel');

    // ---- Health centre ----
    Route::get('clinic', [ClinicController::class, 'index'])->name('clinic.index');
    Route::get('clinic/statistics', [ClinicController::class, 'stats'])->name('clinic.stats');
    Route::get('clinic/patients/{user}', [ClinicController::class, 'patient'])->name('clinic.patient');
    Route::put('clinic/patients/{user}', [ClinicController::class, 'saveDetails'])->name('clinic.details');
    Route::post('clinic/patients/{user}/examination', [ClinicController::class, 'examine'])->name('clinic.examine');
    Route::post('clinic/patients/{user}/visits', [ClinicController::class, 'storeVisit'])->name('clinic.visits.store');
    Route::get('clinic/visits/{visit}/excuse', [ClinicController::class, 'excuse'])->name('clinic.excuse');
    Route::get('my/health', [MyHealthController::class, 'show'])->name('my.health');
    Route::put('my/health', [MyHealthController::class, 'update'])->name('my.health.update');
    Route::get('my/health/visits/{visit}/excuse', [MyHealthController::class, 'excuse'])->name('my.health.excuse');

    // ---- Accommodation ----
    Route::get('hostels/rules', [HostelController::class, 'settings'])->name('accommodation.settings');
    Route::put('hostels/rules', [HostelController::class, 'saveSettings'])->name('accommodation.settings.save');
    Route::get('hostels/allocations', [AllocationController::class, 'index'])->name('accommodation.allocations');
    Route::post('hostels/allocations/run', [AllocationController::class, 'run'])->name('accommodation.run');
    Route::post('hostels/allocations/place', [AllocationController::class, 'place'])->name('accommodation.place');
    Route::post('hostels/allocations/{allocation}/move', [AllocationController::class, 'move'])->name('accommodation.move');
    Route::post('hostels/allocations/{allocation}/cancel', [AllocationController::class, 'cancel'])->name('accommodation.cancel');
    Route::post('hostels/allocations/{allocation}/check-in', [AllocationController::class, 'checkIn'])->name('accommodation.check-in');
    Route::post('hostels/allocations/{allocation}/check-out', [AllocationController::class, 'checkOut'])->name('accommodation.check-out');
    Route::post('hostels/{hostel}/rooms', [HostelController::class, 'addRooms'])->name('hostels.rooms.store');
    Route::put('hostel-rooms/{room}', [HostelController::class, 'updateRoom'])->name('hostels.rooms.update');
    Route::delete('hostel-rooms/{room}', [HostelController::class, 'destroyRoom'])->name('hostels.rooms.destroy');
    Route::resource('hostels', HostelController::class);
    Route::get('my/accommodation', [MyAccommodationController::class, 'show'])->name('my.accommodation');
    Route::post('my/accommodation', [MyAccommodationController::class, 'apply'])->name('my.accommodation.apply');
    Route::post('my/accommodation/withdraw', [MyAccommodationController::class, 'withdraw'])->name('my.accommodation.withdraw');

    // ---- Teaching practice ----
    Route::resource('teaching-practice/schools', TpSchoolController::class)->only(['index', 'store', 'update', 'destroy'])->names('tp.schools')->parameters(['schools' => 'school']);
    Route::get('teaching-practice/supervision', [SupervisionController::class, 'index'])->name('tp.supervise');
    Route::get('teaching-practice/postings/{posting}/assess', [SupervisionController::class, 'assessForm'])->name('tp.assess');
    Route::post('teaching-practice/postings/{posting}/assess', [SupervisionController::class, 'assess'])->name('tp.assess.store');
    Route::post('teaching-practice/postings/{posting}/post', [ExerciseController::class, 'post'])->name('tp.postings.post');
    Route::post('teaching-practice/postings/{posting}/withdraw', [ExerciseController::class, 'withdraw'])->name('tp.postings.withdraw');
    Route::get('teaching-practice/postings/{posting}/letter', [ExerciseController::class, 'letter'])->middleware('permission:View teaching practice|Set up teaching practice|Post students for teaching practice')->name('tp.letter');
    Route::post('teaching-practice/{exercise}/schools', [ExerciseController::class, 'schools'])->name('tp.exercises.schools');
    Route::post('teaching-practice/{exercise}/supervisors', [ExerciseController::class, 'addSupervisor'])->name('tp.exercises.supervisors.store');
    Route::delete('teaching-practice/{exercise}/supervisors/{supervisor}', [ExerciseController::class, 'removeSupervisor'])->name('tp.exercises.supervisors.destroy');
    Route::post('teaching-practice/{exercise}/enrol', [ExerciseController::class, 'enrol'])->name('tp.exercises.enrol');
    Route::post('teaching-practice/{exercise}/auto-post', [ExerciseController::class, 'autoPost'])->name('tp.exercises.auto-post');
    Route::post('teaching-practice/{exercise}/publish', [ExerciseController::class, 'publish'])->name('tp.exercises.publish');
    Route::get('teaching-practice/{exercise}/export', [ExerciseController::class, 'export'])->name('tp.exercises.export');
    Route::resource('teaching-practice', ExerciseController::class)->names('tp.exercises')->parameters(['teaching-practice' => 'exercise']);
    Route::get('my/teaching-practice', [MyTeachingPracticeController::class, 'show'])->name('my.tp');
    Route::post('my/teaching-practice/{posting}', [MyTeachingPracticeController::class, 'choose'])->name('my.tp.choose');
    Route::get('my/teaching-practice/{posting}/letter', [MyTeachingPracticeController::class, 'letter'])->name('my.tp.letter');
});

// Opened by scanning the QR code on a card; public so anyone can check a card.
Route::get('id/{token}', [CardCheckController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{20,64}')->name('id-card.check');
Route::get('excuse/{token}', [ExcuseCheckController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{20,64}')->name('excuse.check');
