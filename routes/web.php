<?php

use App\Http\Controllers\Academics\ApplicantController;
use App\Http\Controllers\Academics\BillController;
use App\Http\Controllers\Academics\MatricSettingsController;
use App\Http\Controllers\Academics\StudentController;
use App\Http\Controllers\Academics\BillItemController;
use App\Http\Controllers\Academics\CourseController;
use App\Http\Controllers\Academics\CurriculumController;
use App\Http\Controllers\Academics\DepartmentController;
use App\Http\Controllers\Academics\FacultyController;
use App\Http\Controllers\Academics\ProgrammeController;
use App\Http\Controllers\Academics\SemesterController;
use App\Http\Controllers\Academics\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

// Login, logout and password reset only. Accounts are created by administrators.
Auth::routes(['register' => false, 'verify' => false, 'confirm' => false]);

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/home', fn () => redirect()->route('dashboard'))->name('home');

    // ---- Users, roles & permissions ----
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
    Route::resource('users', UserController::class)->except(['create', 'edit']);

    Route::post('roles/bulk-remove-users', [RoleController::class, 'bulkRemoveUsers'])->name('roles.bulkremoveusers');
    Route::get('/roles/{role}/users', [RoleController::class, 'getRoleUsers'])->name('roles.users');
    Route::resource('roles', RoleController::class);
    Route::get('/adduser/{id}', [RoleController::class, 'adduser'])->name('roles.adduser');
    Route::post('/updateuserrole', [RoleController::class, 'updateuserrole'])->name('roles.updateuserrole');
    Route::delete('roles/removeuserrole/{userid}/{roleid}', [RoleController::class, 'removeuserrole'])->name('roles.removeuserrole');
    Route::resource('permissions', PermissionController::class);

    // ---- Academic setup ----
    Route::prefix('academics')->name('academics.')->group(function () {
        Route::post('sessions/{session}/make-current', [SessionController::class, 'makeCurrent'])->name('sessions.make-current');
        Route::resource('sessions', SessionController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::post('semesters/{semester}/make-current', [SemesterController::class, 'makeCurrent'])->name('semesters.make-current');
        Route::resource('semesters', SemesterController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::resource('faculties', FacultyController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('programmes', ProgrammeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('courses', CourseController::class)->only(['index', 'store', 'update', 'destroy']);

        // Programme curriculum: which courses are taken at which level/semester
        Route::get('curriculum', [CurriculumController::class, 'index'])->name('curriculum.index');
        Route::post('curriculum', [CurriculumController::class, 'store'])->name('curriculum.store');
        Route::put('curriculum/{entry}', [CurriculumController::class, 'update'])->name('curriculum.update');
        Route::delete('curriculum/{entry}', [CurriculumController::class, 'destroy'])->name('curriculum.destroy');
    });

    // ---- Admissions & students ----
    Route::post('applicants/{applicant}/admit', [ApplicantController::class, 'admit'])->name('applicants.admit');
    Route::post('applicants/{applicant}/reject', [ApplicantController::class, 'reject'])->name('applicants.reject');
    Route::resource('applicants', ApplicantController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('students/matric-format', [MatricSettingsController::class, 'edit'])->name('students.settings');
    Route::put('students/matric-format', [MatricSettingsController::class, 'update'])->name('students.settings.update');
    Route::post('students/{student}/photo', [StudentController::class, 'photo'])->name('students.photo');
    Route::post('students/{student}/status', [StudentController::class, 'status'])->name('students.status');
    Route::post('students/{student}/reset-password', [StudentController::class, 'resetPassword'])->name('students.reset-password');
    Route::resource('students', StudentController::class)->only(['index', 'show', 'update']);

    // ---- School bills ----
    Route::prefix('bills')->name('bills.')->group(function () {
        Route::resource('items', BillItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('copy', [BillController::class, 'copy'])->name('copy');
        Route::resource('structure', BillController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['structure' => 'bill']);
    });
});
