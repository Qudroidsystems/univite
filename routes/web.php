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
        Route::post('application/pay', [\App\Http\Controllers\Payments\OnlinePaymentController::class, 'applicant'])->middleware('throttle:10,1')->name('pay');
    });
});

// ---- Online payments: the gateway sends the payer back here, and gateways call the webhook (no CSRF, see bootstrap/app.php) ----
Route::get('payments/return/{reference}', [\App\Http\Controllers\Payments\OnlinePaymentController::class, 'back'])->middleware('throttle:30,1')->name('payments.return');
Route::post('webhook/payments/{gateway}', [\App\Http\Controllers\Payments\OnlinePaymentController::class, 'webhook'])->middleware('throttle:120,1')->name('payments.webhook');

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
    Route::get('/search', \App\Http\Controllers\SearchController::class)->middleware('throttle:60,1')->name('search');
    Route::get('/lookups/{list}', \App\Http\Controllers\LookupController::class)->middleware('throttle:240,1')->where('list', '[a-z-]+')->name('lookups');

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
        Route::resource('result-keys', \App\Http\Controllers\Academics\ResultKeyController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('degree-classes', DegreeClassController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('course-assignments', CourseAssignmentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('result-stages', ResultStageController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::controller(\App\Http\Controllers\Academics\AssessmentSchemeController::class)->prefix('assessments')->name('assessments.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::post('assign', 'assign')->name('assign');
            Route::delete('assignments/{assignment}', 'unassign')->name('unassign');
            Route::get('{scheme}/edit', 'edit')->name('edit');
            Route::put('{scheme}', 'update')->name('update');
            Route::post('{scheme}/duplicate', 'duplicate')->name('duplicate');
            Route::delete('{scheme}', 'destroy')->name('destroy');
        });
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
    Route::post('payments/{payment}/check', [\App\Http\Controllers\Payments\OnlinePaymentController::class, 'check'])->name('payments.check');
    Route::get('payment-settings', [\App\Http\Controllers\Payments\PaymentSettingsController::class, 'edit'])->name('payments.settings');
    Route::put('payment-settings', [\App\Http\Controllers\Payments\PaymentSettingsController::class, 'update'])->name('payments.settings.update');

    // ---- Fee access rules (share of fees needed to register, write exams, see results, use the portal) and the school loan scheme ----
    Route::controller(\App\Http\Controllers\Fees\FeeAccessController::class)->prefix('fee-access')->name('fee-access.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('excel', 'excel')->name('excel');
        Route::get('print', 'print')->name('print');
        Route::put('/', 'update')->name('update');
        Route::post('students/{student}', 'setException')->name('override');
    });
    Route::controller(\App\Http\Controllers\Fees\LoanAdminController::class)->prefix('loans')->name('loans.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('settings', 'settings')->name('settings');
        Route::get('{loan}', 'show')->whereNumber('loan')->name('show');
        Route::post('{loan}/approve', 'approve')->name('approve');
        Route::post('{loan}/decline', 'decline')->name('decline');
        Route::post('{loan}/disburse', 'disburse')->name('disburse');
        Route::post('{loan}/repay', 'repay')->name('repay');
        Route::post('{loan}/close', 'close')->name('close');
    });
    Route::get('my/loans', [\App\Http\Controllers\Fees\MyLoanController::class, 'index'])->name('my.loans');
    Route::post('my/loans', [\App\Http\Controllers\Fees\MyLoanController::class, 'apply'])->name('my.loans.apply');
    Route::post('my/loans/{loan}/cancel', [\App\Http\Controllers\Fees\MyLoanController::class, 'cancel'])->name('my.loans.cancel');
    Route::get('my/access-locked', [\App\Http\Controllers\Fees\MyLoanController::class, 'locked'])->name('my.access-locked');

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
    Route::get('setup/snapshots', [\App\Http\Controllers\SetupSnapshotController::class, 'index'])->name('setup.snapshots.index');
    Route::post('setup/snapshots', [\App\Http\Controllers\SetupSnapshotController::class, 'store'])->name('setup.snapshots.store');
    Route::get('setup/snapshots/{snapshot}', [\App\Http\Controllers\SetupSnapshotController::class, 'show'])->name('setup.snapshots.show');
    Route::post('setup/snapshots/{snapshot}/restore', [\App\Http\Controllers\SetupSnapshotController::class, 'restore'])->name('setup.snapshots.restore');
    Route::get('setup/snapshots/{snapshot}/download', [\App\Http\Controllers\SetupSnapshotController::class, 'download'])->name('setup.snapshots.download');
    Route::delete('setup/snapshots/{snapshot}', [\App\Http\Controllers\SetupSnapshotController::class, 'destroy'])->name('setup.snapshots.destroy');
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

    // ---- Campus life (mobile app) ----
    Route::prefix('campus')->name('campus.')->controller(\App\Http\Controllers\Campus\CampusAdminController::class)->group(function () {
        Route::get('mobile-app', 'settings')->name('settings');
        Route::put('mobile-app', 'saveSettings')->name('settings.save');
        Route::get('feed', 'feedIndex')->name('feed');
        Route::post('feed', 'storePost')->name('feed.store');
        Route::post('feed/{post}', 'moderatePost')->name('feed.moderate');
        Route::get('marketplace', 'market')->name('market');
        Route::post('marketplace/{listing}', 'moderateListing')->name('market.moderate');
        Route::get('reports', 'reportsIndex')->name('reports');
        Route::post('reports/{report}', 'handleReport')->name('reports.handle');
        Route::get('materials', 'materialsIndex')->name('materials');
        Route::post('materials/{course}', 'storeMaterial')->name('materials.store');
        Route::delete('materials/{material}', 'deleteMaterial')->name('materials.delete');
        Route::get('materials/{material}/download', 'downloadMaterial')->name('materials.download');
    });

    // ---- Results ----
    $rs = \App\Http\Controllers\ResultSheetController::class;
    Route::get('results', [$rs, 'index'])->name('results.sheets.index');
    Route::get('results/open/{course}', [$rs, 'open'])->name('results.open');
    Route::get('results/enter', [\App\Http\Controllers\ScoreEntryController::class, 'index'])->name('results.entry');
    Route::get('results/settings', [\App\Http\Controllers\ResultSettingsController::class, 'index'])->name('results.settings');
    Route::put('results/settings', [\App\Http\Controllers\ResultSettingsController::class, 'update'])->name('results.settings.update');
    Route::get('results/summary', [\App\Http\Controllers\ResultSummaryController::class, 'index'])->name('results.summary');
    Route::post('results/summary/allow/{student}', [\App\Http\Controllers\ResultSummaryController::class, 'waive'])->name('results.summary.waive');
    $cr = \App\Http\Controllers\ComputeResultsController::class;
    Route::get('results/compute', [$cr, 'index'])->name('results.compute');
    Route::post('results/compute', [$cr, 'compute'])->name('results.compute.run');
    Route::get('results/compute/master-sheet/{format}', [$cr, 'masterSheet'])->whereIn('format', ['excel', 'pdf', 'print'])->name('results.compute.sheet');
    $ms = \App\Http\Controllers\MasterSheetController::class;
    Route::get('results/broadsheet', [$ms, 'index'])->name('results.broadsheet');
    Route::get('results/broadsheet/excel', [$ms, 'excel'])->name('results.broadsheet.excel');
    Route::get('results/broadsheet/print', [$ms, 'print'])->name('results.broadsheet.print');
    Route::post('results/broadsheet/decide', [$ms, 'decide'])->name('results.broadsheet.decide');
    $cw = \App\Http\Controllers\CarryoverWaiverController::class;
    Route::get('results/carryover-waivers', [$cw, 'index'])->name('results.waivers.index');
    Route::post('results/carryover-waivers', [$cw, 'store'])->name('results.waivers.store');
    Route::post('results/carryover-waivers/{waiver}/withdraw', [$cw, 'withdraw'])->name('results.waivers.withdraw');
    Route::post('results/amend/{result}', [$rs, 'amend'])->name('results.amend');
    Route::get('results/complaints', [\App\Http\Controllers\ResultComplaintController::class, 'index'])->name('results.complaints');
    Route::post('results/complaints/{complaint}', [\App\Http\Controllers\ResultComplaintController::class, 'respond'])->name('results.complaints.respond');
    Route::post('my/results/{result}/query', [\App\Http\Controllers\ResultComplaintController::class, 'store'])->name('my.results.query');

    // ---- Senate result approval ----
    Route::controller(\App\Http\Controllers\Senate\SenateController::class)->prefix('senate')->name('senate.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('export/{format}', 'export')->whereIn('format', ['print', 'pdf', 'excel'])->name('export');
        Route::post('decide', 'decide')->name('decide');
        Route::post('approve-eligible', 'approveEligible')->name('approve-eligible');
        Route::put('settings', 'settings')->name('settings');
    });

    // ---- Graduation ----
    $gr = \App\Http\Controllers\GraduationController::class;
    Route::get('graduation', [$gr, 'index'])->name('graduation.index');
    Route::post('graduation/run', [$gr, 'run'])->name('graduation.run');
    Route::get('graduation/{list}', [$gr, 'show'])->name('graduation.show');
    Route::post('graduation/{list}/approve', [$gr, 'approve'])->name('graduation.approve');
    Route::post('graduation/entries/{entry}/exclude', [$gr, 'exclude'])->name('graduation.exclude');
    Route::get('results/{sheet}', [$rs, 'show'])->name('results.sheets.show');
    Route::post('results/{sheet}/save', [$rs, 'save'])->name('results.sheets.save');
    Route::post('results/{sheet}/correct', [$rs, 'correct'])->name('results.sheets.correct');
    Route::post('results/{sheet}/upload', [$rs, 'upload'])->name('results.sheets.upload');
    Route::get('results/{sheet}/template', [$rs, 'template'])->name('results.sheets.template');
    Route::post('results/{sheet}/submit', [$rs, 'submit'])->name('results.sheets.submit');
    Route::post('results/{sheet}/decide', [$rs, 'decide'])->name('results.sheets.decide');
    Route::post('results/{sheet}/scheme', [$rs, 'scheme'])->name('results.sheets.scheme');
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

    // ---- O Level results: students submit, the AI reads the upload, staff confirm ----
    Route::controller(\App\Http\Controllers\Olevel\MyOlevelController::class)->prefix('my/olevel')->name('my.olevel')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->name('.store');
        Route::put('{sitting}', 'update')->name('.update');
        Route::post('{sitting}/submit', 'submit')->name('.submit');
        Route::post('{sitting}/read', 'read')->middleware('throttle:10,1')->name('.read');
        Route::delete('{sitting}', 'destroy')->name('.destroy');
        Route::get('{sitting}/document', 'document')->name('.document');
    });
    Route::get('olevel/requirements', [\App\Http\Controllers\Olevel\OlevelRequirementController::class, 'index'])->name('olevel.requirements');
    Route::put('olevel/requirements', [\App\Http\Controllers\Olevel\OlevelRequirementController::class, 'save'])->name('olevel.requirements.save');
    Route::put('olevel/settings', [\App\Http\Controllers\Olevel\OlevelRequirementController::class, 'settings'])->name('olevel.settings');
    Route::controller(\App\Http\Controllers\Olevel\OlevelVerificationController::class)->prefix('olevel')->name('olevel.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{sitting}', 'show')->whereNumber('sitting')->name('show');
        Route::post('{sitting}/confirm', 'confirm')->name('confirm');
        Route::post('{sitting}/reject', 'reject')->name('reject');
        Route::post('{sitting}/read', 'read')->middleware('throttle:20,1')->name('read');
        Route::get('{sitting}/document', 'document')->name('document');
        Route::post('students/{student}/override', 'allowRegistration')->name('override');
    });

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
    Route::post('my/fees/{invoice}/pay', [\App\Http\Controllers\Payments\OnlinePaymentController::class, 'student'])->middleware('throttle:10,1')->name('my.fees.pay');
    Route::get('my/receipts/{payment}', [StudentFeesController::class, 'receipt'])->name('my.receipt');

    // ---- School bills ----
    Route::prefix('bills')->name('bills.')->group(function () {
        Route::resource('items', BillItemController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('copy', [BillController::class, 'copy'])->name('copy');
        Route::resource('structure', BillController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['structure' => 'bill']);
    });
    // ---- Venues: halls, classrooms and laboratories ----
    Route::prefix('venues')->name('venues.')->controller(\App\Http\Controllers\VenueController::class)->group(function () {
        Route::get('availability', 'availability')->name('availability');
        Route::get('usage', 'usage')->name('usage');
        Route::post('{venue}/closures', 'close')->name('closures.store');
        Route::post('{venue}/closures/{closure}/lift', 'lift')->name('closures.lift');
        Route::post('{venue}/issues', 'report')->name('issues.store');
        Route::post('{venue}/issues/{issue}/resolve', 'resolve')->name('issues.resolve');
        Route::post('{venue}/bookings', 'book')->name('bookings.store');
        Route::post('{venue}/bookings/{booking}/cancel', 'cancelBooking')->name('bookings.cancel');
    });
    Route::resource('venues', \App\Http\Controllers\VenueController::class);

    // ---- Lecture and examination timetables ----
    Route::get('timetables/staff-availability', [\App\Http\Controllers\StaffAvailabilityController::class, 'index'])->name('staff-availability.index');
    Route::post('timetables/staff-availability', [\App\Http\Controllers\StaffAvailabilityController::class, 'store'])->name('staff-availability.store');
    Route::delete('timetables/staff-availability/{row}', [\App\Http\Controllers\StaffAvailabilityController::class, 'destroy'])->name('staff-availability.destroy');
    // ---- The generation wizard (the whole school at once), candidates, analytics and versions ----
    Route::prefix('timetables')->name('timetables.')->group(function () {
        Route::controller(\App\Http\Controllers\Timetable\TimetableWizardController::class)->group(function () {
            Route::get('wizard', 'show')->name('wizard');
            Route::post('wizard/courses', 'courses')->name('wizard.courses');
            Route::post('wizard/preview', 'preview')->name('wizard.preview');
            Route::post('wizard', 'store')->name('wizard.store');
            Route::post('wizard/templates', 'saveTemplate')->name('wizard.templates.store');
            Route::delete('wizard/templates/{template}', 'deleteTemplate')->name('wizard.templates.destroy');
        });
        Route::controller(\App\Http\Controllers\Timetable\TimetableRunController::class)->group(function () {
            Route::get('{timetable}/candidates', 'index')->name('runs');
            Route::get('{timetable}/candidates/status', 'status')->name('runs.status');
            Route::post('{timetable}/candidates', 'store')->name('runs.store');
            Route::post('{timetable}/candidates/run-now', 'runNow')->name('runs.now');
            Route::get('{timetable}/candidates/compare', 'compare')->name('runs.compare');
            Route::get('{timetable}/candidates/{run}', 'show')->name('runs.show');
            Route::post('{timetable}/candidates/{run}/apply', 'apply')->name('runs.apply');
            Route::get('{timetable}/analytics', 'analytics')->name('analytics');
            Route::post('{timetable}/analytics/refresh', 'refresh')->name('analytics.refresh');
            Route::get('{timetable}/versions', 'versions')->name('versions');
            Route::post('{timetable}/versions/{from}/restore', 'restore')->name('versions.restore');
        });
    });
    Route::prefix('timetables')->name('timetables.')->controller(\App\Http\Controllers\TimetableController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{timetable}', 'show')->name('show');
        Route::get('{timetable}/print', 'print')->name('print');
        Route::get('{timetable}/settings', 'settings')->name('settings');
        Route::put('{timetable}/settings', 'updateSettings')->name('settings.update');
        Route::get('{timetable}/items', 'items')->name('items');
        Route::put('{timetable}/items/{item}', 'updateItem')->name('items.update');
        Route::post('{timetable}/items', 'addItem')->name('items.add');
        Route::post('{timetable}/items/sync', 'syncItems')->name('items.sync');
        Route::post('{timetable}/generate', 'generate')->name('generate');
        Route::get('{timetable}/what-if', 'whatIf')->name('whatif');
        Route::get('{timetable}/invigilators', 'invigilators')->name('invigilators');
        Route::post('{timetable}/invigilators', 'replan')->name('replan');
        Route::post('{timetable}/submit', 'submit')->name('submit');
        Route::post('{timetable}/approve', 'approve')->name('approve');
        Route::post('{timetable}/return', 'returnIt')->name('return');
        Route::post('{timetable}/publish', 'publish')->name('publish');
        Route::post('{timetable}/revise', 'revise')->name('revise');
        Route::delete('{timetable}', 'destroy')->name('destroy');
        Route::get('{timetable}/entries/{unit}', 'entry')->name('entry');
        Route::post('{timetable}/entries/{unit}/move', 'move')->name('move');
        Route::post('{timetable}/entries/{unit}/unlock', 'unlock')->name('unlock');
    });

    // ---- Examination seating ----
    Route::prefix('seating')->name('seating.')->controller(\App\Http\Controllers\SeatingController::class)->group(function () {
        Route::get('timetable/{timetable}', 'show')->name('show');
        Route::post('timetable/{timetable}/generate', 'generate')->name('generate');
        Route::get('timetable/{timetable}/hall/{slot}/{venue}', 'hall')->name('hall');
        Route::post('seats/{seat}/move', 'move')->name('move');
        Route::post('seats/{seat}/attendance', 'attendance')->name('attendance');
        Route::post('plans/{plan}/late', 'seatLate')->name('late');
        Route::post('plans/{plan}/release', 'release')->name('release');
        Route::post('plans/{plan}/approve', 'approve')->name('approve');
        Route::post('plans/{plan}/publish', 'publish')->name('publish');
    });
    Route::get('seating/verify/{code}', [\App\Http\Controllers\MyScheduleController::class, 'verify'])->name('seating.verify');
    Route::post('seating/verify/{code}', [\App\Http\Controllers\MyScheduleController::class, 'checkIn'])->name('seating.verify.mark');

    // ---- A student's or lecturer's own timetable, papers and duties ----
    Route::get('my/timetable', [\App\Http\Controllers\MyScheduleController::class, 'timetable'])->name('my.timetable');
    Route::get('my/examinations', [\App\Http\Controllers\MyScheduleController::class, 'exams'])->name('my.exams');
    Route::get('my/gpa-planner', \App\Http\Controllers\MyGpaPlannerController::class)->name('my.gpa-planner');
    Route::get('my/examinations/slip', [\App\Http\Controllers\MyScheduleController::class, 'slip'])->name('my.exams.slip');
    Route::get('my/teaching', [\App\Http\Controllers\MyScheduleController::class, 'teaching'])->name('my.teaching');

    // ---- Staff leave ----
    Route::prefix('leave')->name('leave.')->group(function () {
        Route::controller(\App\Http\Controllers\LeaveAdminController::class)->group(function () {
            Route::get('register', 'register')->name('register');
            Route::get('settings', 'settings')->name('settings');
            Route::put('settings', 'updateSettings')->name('settings.update');
            Route::put('routes', 'updateRoute')->name('routes.update');
            Route::get('balances', 'balances')->name('balances');
            Route::post('balances/adjust', 'adjust')->name('balances.adjust');
            Route::post('balances/carry-over', 'carryOver')->name('balances.carryover');
        });
        Route::resource('types', \App\Http\Controllers\Leave\LeaveTypeController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('entitlements', \App\Http\Controllers\Leave\LeaveEntitlementController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('holidays', \App\Http\Controllers\Leave\HolidayController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::redirect('staff', '/staff')->name('staff.index');   // staff profiles moved to Staff Records
        Route::controller(\App\Http\Controllers\LeaveController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('apply', 'create')->name('create');
            Route::get('check', 'check')->name('check');
            Route::post('/', 'store')->name('store');
            Route::get('approvals', 'approvals')->name('approvals');
            Route::get('calendar', 'calendar')->name('calendar');
            Route::get('documents/{document}', 'document')->name('document');
            Route::get('{leave}', 'show')->name('show');
            Route::get('{leave}/edit', 'edit')->name('edit');
            Route::put('{leave}', 'update')->name('update');
            Route::post('{leave}/decide', 'decide')->name('decide');
            Route::post('{leave}/acting', 'acting')->name('acting');
            Route::post('{leave}/cancel', 'cancel')->name('cancel');
            Route::post('{leave}/resume', 'resume')->name('resume');
            Route::get('{leave}/letters/{kind}', 'letter')->name('letter');
        });
    });

    // ---- Messaging and notifications ----
    Route::get('messaging/settings', [\App\Http\Controllers\MessagingController::class, 'settings'])->name('messaging.settings');
    Route::put('messaging/settings', [\App\Http\Controllers\MessagingController::class, 'update'])->name('messaging.settings.update');
    Route::post('messaging/test', [\App\Http\Controllers\MessagingController::class, 'test'])->name('messaging.test');
    Route::get('messaging/log', [\App\Http\Controllers\MessagingController::class, 'log'])->name('messaging.log');
    Route::post('messaging/retry', [\App\Http\Controllers\MessagingController::class, 'retry'])->name('messaging.retry');
    Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/settings', [\App\Http\Controllers\NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::put('notifications/settings', [\App\Http\Controllers\NotificationController::class, 'savePreferences'])->name('notifications.preferences.save');
    Route::get('notifications/{id}', [\App\Http\Controllers\NotificationController::class, 'open'])->name('notifications.open');
    // ---- Staff records, units, payroll, scoped roles ----
    Route::controller(\App\Http\Controllers\Hr\StaffRecordController::class)->group(function () {
        Route::get('staff/retirements', 'retirements')->name('staff.retirements');
        Route::put('staff/{staff}/pay', 'updatePay')->name('staff.pay');
        Route::post('staff/{staff}/pay-items', 'storeItem')->name('staff.items.store');
        Route::put('staff-pay-items/{item}', 'updateItem')->name('staff.items.update');
    });
    Route::resource('staff', \App\Http\Controllers\Hr\StaffRecordController::class)->only(['index', 'store', 'update', 'show'])->parameters(['staff' => 'staff']);
    Route::resource('units', \App\Http\Controllers\Hr\UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::resource('structures', \App\Http\Controllers\Payroll\SalaryStructureController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('elements', \App\Http\Controllers\Payroll\PayElementController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('scales/{structure}', [\App\Http\Controllers\Payroll\SalaryScaleController::class, 'show'])->name('scales.show');
        Route::put('scales/{structure}', [\App\Http\Controllers\Payroll\SalaryScaleController::class, 'update'])->name('scales.update');
        Route::post('scales/{structure}/upload', [\App\Http\Controllers\Payroll\SalaryScaleController::class, 'upload'])->name('scales.upload');
        Route::get('settings', [\App\Http\Controllers\Payroll\PayrollSettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [\App\Http\Controllers\Payroll\PayrollSettingsController::class, 'update'])->name('settings.update');
        Route::controller(\App\Http\Controllers\Payroll\PayrollRunController::class)->group(function () {
            Route::get('/', 'index')->name('runs.index');
            Route::post('/', 'store')->name('runs.store');
            Route::get('payslips/{payslip}', 'payslip')->name('payslips.show');
            Route::get('{run}', 'show')->name('runs.show');
            Route::delete('{run}', 'destroy')->name('runs.destroy');
            Route::post('{run}/approve', 'approve')->name('runs.approve');
            Route::post('{run}/paid', 'paid')->name('runs.paid');
            Route::get('{run}/schedules/{kind}', 'schedule')->name('runs.schedule');
        });
    });
    Route::get('my/payslips', [\App\Http\Controllers\Payroll\MyPayslipController::class, 'index'])->name('my.payslips');
    Route::get('my/payslips/{payslip}', [\App\Http\Controllers\Payroll\MyPayslipController::class, 'show'])->name('my.payslips.show');
    Route::get('access/scoped-roles', [\App\Http\Controllers\ScopedRoleController::class, 'index'])->name('scoped-roles.index');
    Route::post('access/scoped-roles', [\App\Http\Controllers\ScopedRoleController::class, 'store'])->name('scoped-roles.store');
    Route::put('access/scoped-roles/settings', [\App\Http\Controllers\ScopedRoleController::class, 'settings'])->name('scoped-roles.settings');
    Route::delete('access/scoped-roles/{scopedRole}', [\App\Http\Controllers\ScopedRoleController::class, 'destroy'])->name('scoped-roles.destroy');
    Route::get('activity/{type}/{id}', [\App\Http\Controllers\ActivityController::class, 'show'])->whereNumber('id')->name('activity.show');
    // ---- Results from before the portal ----
    Route::prefix('legacy-results')->name('legacy.')->controller(\App\Http\Controllers\LegacyImportController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('templates/{kind}', 'template')->name('template');
        Route::get('{import}', 'show')->name('show');
        Route::post('{import}/check', 'check')->name('check');
        Route::post('{import}/import', 'import')->name('import');
        Route::get('{import}/problems', 'problems')->name('problems');
        Route::delete('{import}', 'destroy')->name('destroy');
    });
});

require __DIR__ . '/campus.php';
