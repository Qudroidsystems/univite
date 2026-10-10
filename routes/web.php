<?php

use App\Http\Controllers\Academics\ApplicantController;
use App\Http\Controllers\Academics\ApplicationSettingsController;
use App\Http\Controllers\Apply\AdmissionStatusController;
use App\Http\Controllers\Apply\ApplicationPortalController;
use App\Http\Controllers\StudentOnboardingController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\ClearanceController;
use App\Http\Controllers\MatricController;
use App\Http\Controllers\StudentClearanceController;
use App\Http\Controllers\Academics\ClearanceItemController;
use App\Http\Controllers\Academics\BillController;
use App\Http\Controllers\Academics\MatricSettingsController;
use App\Http\Controllers\Academics\StudentController;
use App\Http\Controllers\Academics\BillItemController;
use App\Http\Controllers\Academics\CourseController;
use App\Http\Controllers\Academics\EntryModeController;
use App\Http\Controllers\Academics\LookupController;
use App\Http\Controllers\Academics\SemesterTypeController;
use App\Http\Controllers\Academics\GradeBandController;
use App\Http\Controllers\Academics\CourseAssignmentController;
use App\Http\Controllers\Academics\ResultStageController;
use App\Http\Controllers\Academics\DegreeClassController;
use App\Http\Controllers\Academics\CustomFieldController;
use App\Http\Controllers\Academics\StudentStatusController;
use App\Http\Controllers\Academics\StudyCategoryController;
use App\Http\Controllers\Academics\CurriculumController;
use App\Http\Controllers\Academics\DepartmentController;
use App\Http\Controllers\Academics\FacultyController;
use App\Http\Controllers\Academics\ProgrammeController;
use App\Http\Controllers\Academics\SemesterController;
use App\Http\Controllers\Academics\SessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\StudentFeesController;
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
Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : view('welcome'));
// Tiny endpoint the browser pings to measure connection quality (see public/js/univite-app.js).
Route::get('ping', fn () => response('', 204)->header('Cache-Control', 'no-store'))->name('ping');

// ---- Check an admission outcome without signing in ----
Route::get('admission-status', [AdmissionStatusController::class, 'form'])->name('admission-status');
Route::post('admission-status', [AdmissionStatusController::class, 'check'])->middleware('throttle:10,1')->name('admission-status.check');

// ---- Online application (public) ----
Route::prefix('apply')->name('apply.')->group(function () {
    Route::get('/', [ApplicationPortalController::class, 'register'])->name('register');
    Route::post('/', [ApplicationPortalController::class, 'store'])->middleware('throttle:20,1')->name('store');
    Route::get('login', [ApplicationPortalController::class, 'login'])->name('login');
    Route::post('login', [ApplicationPortalController::class, 'attempt'])->name('login.attempt');

    Route::middleware('applicant')->group(function () {
        Route::post('logout', [ApplicationPortalController::class, 'logout'])->name('logout');
        Route::get('application', [ApplicationPortalController::class, 'dashboard'])->name('dashboard');
        Route::put('application/profile', [ApplicationPortalController::class, 'updateProfile'])->name('profile');
        Route::post('application/results', [ApplicationPortalController::class, 'storeResult'])->name('results.store');
        Route::delete('application/results/{result}', [ApplicationPortalController::class, 'destroyResult'])->name('results.destroy');
        Route::post('application/documents', [ApplicationPortalController::class, 'storeDocument'])->name('documents.store');
        Route::get('application/documents/{document}', [ApplicationPortalController::class, 'showDocument'])->name('documents.show');
        Route::delete('application/documents/{document}', [ApplicationPortalController::class, 'destroyDocument'])->name('documents.destroy');
        Route::get('application/custom-files/{value}', [ApplicationPortalController::class, 'customFile'])->name('custom-files');
        Route::post('application/submit', [ApplicationPortalController::class, 'submit'])->name('submit');
        Route::get('application/slip', [ApplicationPortalController::class, 'slip'])->name('slip');
    });
});

// Login, logout and password reset only. Accounts are created by administrators.
Auth::routes(['register' => false, 'verify' => false, 'confirm' => false]);

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('custom-fields/files/{value}', [\App\Http\Controllers\CustomFieldFileController::class, 'download'])->name('custom-fields.download');
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

    // ---- Institution profile (name, address, logo ...) ----
    Route::get('institution', [\App\Http\Controllers\InstitutionController::class, 'edit'])->name('institution.edit');
    Route::put('institution', [\App\Http\Controllers\InstitutionController::class, 'update'])->name('institution.update');

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

        Route::resource('study-categories', StudyCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('semester-types', SemesterTypeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('student-statuses', StudentStatusController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('entry-modes', EntryModeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('grade-bands', GradeBandController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('degree-classes', DegreeClassController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('course-assignments', CourseAssignmentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('result-stages', ResultStageController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('custom-fields', CustomFieldController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('lists', LookupController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('clearance-items', ClearanceItemController::class)->only(['index', 'store', 'update', 'destroy']);

        // Academic rules (credit load, carry-overs, CGPA, graduation) per university / faculty / department / programme
        Route::get('rules', [\App\Http\Controllers\Academics\AcademicRuleController::class, 'index'])->name('rules.index');
        Route::put('rules', [\App\Http\Controllers\Academics\AcademicRuleController::class, 'update'])->name('rules.update');
        Route::post('rules/admins/{department}', [\App\Http\Controllers\Academics\AcademicRuleController::class, 'admins'])->name('rules.admins');
        Route::post('rules/advisers/{programme}', [\App\Http\Controllers\Academics\AcademicRuleController::class, 'addAdviser'])->name('rules.advisers.store');
        Route::delete('rules/advisers/{adviser}', [\App\Http\Controllers\Academics\AcademicRuleController::class, 'removeAdviser'])->name('rules.advisers.destroy');

        // Programme curriculum: which courses are taken at which level/semester
        Route::get('curriculum', [CurriculumController::class, 'index'])->name('curriculum.index');
        Route::post('curriculum', [CurriculumController::class, 'store'])->name('curriculum.store');
        Route::put('curriculum/{entry}', [CurriculumController::class, 'update'])->name('curriculum.update');
        Route::delete('curriculum/{entry}', [CurriculumController::class, 'destroy'])->name('curriculum.destroy');
    });

    // ---- Admissions & students ----
    Route::get('applicants/settings', [ApplicationSettingsController::class, 'edit'])->name('applicants.settings');
    Route::put('applicants/settings', [ApplicationSettingsController::class, 'update'])->name('applicants.settings.update');
    Route::post('applicants/admit-batch', [ApplicantController::class, 'admitBatch'])->name('applicants.admit-batch');
    Route::get('applicants/documents/{document}', [ApplicantController::class, 'document'])->name('applicants.documents.show');
    Route::get('applicants/{applicant}', [ApplicantController::class, 'show'])->name('applicants.show')->whereNumber('applicant');
    Route::post('applicants/{applicant}/admit', [ApplicantController::class, 'admit'])->name('applicants.admit');
    Route::post('applicants/{applicant}/reject', [ApplicantController::class, 'reject'])->name('applicants.reject');
    Route::resource('applicants', ApplicantController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('students/matric-format', [MatricSettingsController::class, 'edit'])->name('students.settings');
    Route::put('students/matric-format', [MatricSettingsController::class, 'update'])->name('students.settings.update');
    Route::put('students/matric-format/issue', [MatricSettingsController::class, 'updateIssue'])->name('students.settings.issue');
    Route::post('students/{student}/photo', [StudentController::class, 'photo'])->name('students.photo');
    Route::post('students/{student}/status', [StudentController::class, 'status'])->name('students.status');
    Route::post('students/{student}/reset-password', [StudentController::class, 'resetPassword'])->name('students.reset-password');
    Route::resource('students', StudentController::class)->only(['index', 'show', 'update']);

    // ---- Invoices & payments ----
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/generate', [InvoiceController::class, 'preview'])->name('invoices.generate.preview');
    Route::post('invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::get('payments/{payment}/receipt', [InvoiceController::class, 'receipt'])->name('payments.receipt');

    // ---- New student verification (staff) ----
    Route::get('verification', [VerificationController::class, 'index'])->name('verification.index');
    Route::get('verification/documents/{document}', [VerificationController::class, 'document'])->name('verification.documents.show');
    Route::post('verification/{student}/verify', [VerificationController::class, 'verify'])->name('verification.verify');
    Route::post('verification/{student}/unverify', [VerificationController::class, 'unverify'])->name('verification.unverify');

    // ---- Matric numbers (after clearance) ----
    Route::get('matric', [MatricController::class, 'index'])->name('matric.index');
    Route::get('matric/generate', [MatricController::class, 'preview'])->name('matric.generate.preview');
    Route::post('matric/generate', [MatricController::class, 'generate'])->name('matric.generate');
    Route::post('matric/import', [MatricController::class, 'import'])->name('matric.import');
    Route::get('matric/approvals', [\App\Http\Controllers\MatricApprovalController::class, 'index'])->name('matric.approvals.index');
    Route::get('matric/approvals/{batch}', [\App\Http\Controllers\MatricApprovalController::class, 'show'])->name('matric.approvals.show');
    Route::post('matric/approvals/{batch}/approve', [\App\Http\Controllers\MatricApprovalController::class, 'approve'])->name('matric.approvals.approve');
    Route::post('matric/approvals/{batch}/reject', [\App\Http\Controllers\MatricApprovalController::class, 'reject'])->name('matric.approvals.reject');
    Route::put('students/matric-format/approval', [MatricSettingsController::class, 'updateApproval'])->name('students.settings.approval');
    Route::put('students/matric-format/sequence', [MatricSettingsController::class, 'updateSequence'])->name('students.settings.sequence');
    Route::post('matric/undo', [MatricController::class, 'undo'])->name('matric.undo');
    Route::post('matric/{student}/special', [MatricController::class, 'special'])->name('matric.special');
    Route::post('matric/{student}/cancel', [MatricController::class, 'cancel'])->name('matric.cancel');
    Route::post('matric/{student}/restore', [MatricController::class, 'restore'])->name('matric.restore');

    // ---- Clearance (staff) ----
    Route::get('clearance', [ClearanceController::class, 'index'])->name('clearance.index');
    Route::post('clearance/bulk', [ClearanceController::class, 'bulk'])->name('clearance.bulk');
    Route::get('clearance/{student}', [ClearanceController::class, 'show'])->name('clearance.show');
    Route::get('clearance/{student}/slip', [ClearanceController::class, 'slip'])->name('clearance.slip');
    Route::post('clearance/{student}/items/{item}/decide', [ClearanceController::class, 'decide'])->name('clearance.decide');
    Route::post('clearance/{student}/items/{item}/reset', [ClearanceController::class, 'reset'])->name('clearance.reset');

    // ---- Session lifecycle, copying and the operation log ----
    Route::get('sessions/rules', [\App\Http\Controllers\SessionRulesController::class, 'index'])->name('sessions.rules');
    Route::put('sessions/rules', [\App\Http\Controllers\SessionRulesController::class, 'update'])->name('sessions.rules.update');
    Route::get('sessions/copy', [\App\Http\Controllers\SessionCloneController::class, 'index'])->name('sessions.clone');
    Route::post('sessions/copy', [\App\Http\Controllers\SessionCloneController::class, 'run'])->name('sessions.clone.run');
    Route::post('sessions/copy/{batch}/undo', [\App\Http\Controllers\SessionCloneController::class, 'undo'])->name('sessions.clone.undo');
    Route::get('sessions/{session}/status', [\App\Http\Controllers\SessionLifecycleController::class, 'show'])->name('sessions.lifecycle');
    Route::post('sessions/{session}/status', [\App\Http\Controllers\SessionLifecycleController::class, 'change'])->name('sessions.lifecycle.change');
    Route::get('operations', [\App\Http\Controllers\OperationHistoryController::class, 'index'])->name('operations.index');
    Route::get('operations/{batch}', [\App\Http\Controllers\OperationHistoryController::class, 'show'])->name('operations.show');
    Route::get('operations/{batch}/reverse', [\App\Http\Controllers\ReversalController::class, 'preview'])->name('operations.reverse');
    Route::post('operations/{batch}/reverse', [\App\Http\Controllers\ReversalController::class, 'run'])->name('operations.reverse.run');
    Route::get('payments/{payment}/reverse', [InvoiceController::class, 'reverse'])->name('payments.reverse');

    // ---- Emailed documents ----
    $de = \App\Http\Controllers\DocumentEmailController::class;
    Route::get('emails', [$de, 'index'])->name('emails.index');
    Route::put('emails/settings', [$de, 'settings'])->name('emails.settings');
    Route::post('emails/test', [$de, 'test'])->name('emails.test');
    Route::post('emails/{email}/resend', [$de, 'resend'])->name('emails.resend');
    Route::post('my/documents/{type}/{id}/email', [$de, 'mine'])->name('my.documents.email');

    // ---- Results ----
    $rs = \App\Http\Controllers\ResultSheetController::class;
    Route::get('results', [$rs, 'index'])->name('results.sheets.index');
    Route::get('results/open/{course}', [$rs, 'open'])->name('results.open');
    Route::get('results/settings', [\App\Http\Controllers\ResultSettingsController::class, 'index'])->name('results.settings');
    Route::put('results/settings', [\App\Http\Controllers\ResultSettingsController::class, 'update'])->name('results.settings.update');
    Route::get('results/summary', [\App\Http\Controllers\ResultSummaryController::class, 'index'])->name('results.summary');
    Route::post('results/summary/allow/{student}', [\App\Http\Controllers\ResultSummaryController::class, 'waive'])->name('results.summary.waive');
    Route::get('results/broadsheet', [\App\Http\Controllers\StudentResultController::class, 'broadsheet'])->name('results.broadsheet');
    Route::post('results/amend/{result}', [$rs, 'amend'])->name('results.amend');
    Route::get('results/complaints', [\App\Http\Controllers\ResultComplaintController::class, 'index'])->name('results.complaints');
    Route::post('results/complaints/{complaint}', [\App\Http\Controllers\ResultComplaintController::class, 'respond'])->name('results.complaints.respond');
    Route::post('my/results/{result}/query', [\App\Http\Controllers\ResultComplaintController::class, 'store'])->name('my.results.query');

    // ---- Graduation ----
    $gr = \App\Http\Controllers\GraduationController::class;
    Route::get('graduation', [$gr, 'index'])->name('graduation.index');
    Route::post('graduation/run', [$gr, 'run'])->name('graduation.run');
    Route::get('graduation/{list}', [$gr, 'show'])->name('graduation.show');
    Route::post('graduation/{list}/approve', [$gr, 'approve'])->name('graduation.approve');
    Route::post('graduation/entries/{entry}/exclude', [$gr, 'exclude'])->name('graduation.exclude');
    Route::get('results/{sheet}', [$rs, 'show'])->name('results.sheets.show');
    Route::post('results/{sheet}/save', [$rs, 'save'])->name('results.sheets.save');
    Route::post('results/{sheet}/upload', [$rs, 'upload'])->name('results.sheets.upload');
    Route::get('results/{sheet}/template', [$rs, 'template'])->name('results.sheets.template');
    Route::post('results/{sheet}/submit', [$rs, 'submit'])->name('results.sheets.submit');
    Route::post('results/{sheet}/decide', [$rs, 'decide'])->name('results.sheets.decide');
    Route::get('my/results', [\App\Http\Controllers\StudentResultController::class, 'mine'])->name('my.results');
    Route::get('students/{student}/transcript', [\App\Http\Controllers\StudentResultController::class, 'transcript'])->name('students.transcript');

    // ---- Course registration ----
    Route::get('registrations', [\App\Http\Controllers\RegistrationApprovalController::class, 'index'])->name('registrations.index');
    Route::post('registrations/bulk', [\App\Http\Controllers\RegistrationApprovalController::class, 'bulk'])->name('registrations.bulk');
    Route::get('registrations/settings', [\App\Http\Controllers\RegistrationSettingsController::class, 'index'])->name('registrations.settings');
    Route::put('registrations/settings', [\App\Http\Controllers\RegistrationSettingsController::class, 'update'])->name('registrations.settings.update');
    Route::get('registrations/{form}', [\App\Http\Controllers\RegistrationApprovalController::class, 'show'])->name('registrations.show');
    Route::post('registrations/{form}/decide', [\App\Http\Controllers\RegistrationApprovalController::class, 'decide'])->name('registrations.decide');
    Route::post('registrations/{form}/reopen', [\App\Http\Controllers\RegistrationApprovalController::class, 'reopen'])->name('registrations.reopen');
    Route::get('my/registration', [\App\Http\Controllers\StudentRegistrationController::class, 'index'])->name('my.registration');
    Route::post('my/registration', [\App\Http\Controllers\StudentRegistrationController::class, 'save'])->name('my.registration.save');
    Route::get('my/registration/{form}/print', [\App\Http\Controllers\StudentRegistrationController::class, 'print'])->name('my.registration.print');

    // ---- A student's own clearance ----
    Route::get('my/clearance', [StudentClearanceController::class, 'index'])->name('my.clearance');
    Route::get('my/clearance/slip', [StudentClearanceController::class, 'slip'])->name('my.clearance.slip');
    Route::post('my/clearance/{item}/resubmit', [StudentClearanceController::class, 'resubmit'])->name('my.clearance.resubmit');

    // ---- A newly admitted student's own steps ----
    Route::get('my/getting-started', [StudentOnboardingController::class, 'index'])->name('my.onboarding');
    Route::post('my/getting-started/accept', [StudentOnboardingController::class, 'accept'])->name('my.onboarding.accept');
    Route::put('my/getting-started/biodata', [StudentOnboardingController::class, 'updateProfile'])->name('my.onboarding.biodata');
    Route::post('my/getting-started/documents', [StudentOnboardingController::class, 'storeDocument'])->name('my.onboarding.documents.store');
    Route::get('my/getting-started/documents/{document}', [StudentOnboardingController::class, 'showDocument'])->name('my.onboarding.documents.show');

    // ---- A signed-in student's own fees (no staff permission needed) ----
    Route::get('my/fees', [StudentFeesController::class, 'index'])->name('my.fees');
    Route::get('my/fees/{invoice}', [StudentFeesController::class, 'show'])->name('my.fees.show');
    Route::get('my/receipts/{payment}', [StudentFeesController::class, 'receipt'])->name('my.receipt');

    // ---- School bills ----
    Route::prefix('bills')->name('bills.')->group(function () {
        Route::resource('items', BillItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('copy', [BillController::class, 'copy'])->name('copy');
        Route::resource('structure', BillController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['structure' => 'bill']);
    });
});
