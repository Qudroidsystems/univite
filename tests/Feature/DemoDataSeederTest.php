<?php

use App\Models\AcademicSession;
use App\Models\BedAllocation;
use App\Models\CourseResult;
use App\Models\Invoice;
use App\Models\LibraryLoan;
use App\Models\Payment;
use App\Models\RegistrationForm;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('leaves sample data out of the normal seed outside development', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Student::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('seeds a whole sample university that hangs together', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoDataSeeder::class);

    $current = AcademicSession::current();
    expect($current?->name)->toBe('2026/2027');

    // Students at every level, with matric numbers from their entry session.
    // The university's schools, departments and programmes: degree, NCE, part-time and sandwich, with combinations.
    expect(\App\Models\Faculty::count())->toBeGreaterThanOrEqual(12)
        ->and(\App\Models\Department::count())->toBeGreaterThanOrEqual(50)
        ->and(\App\Models\Programme::where('is_combination', true)->count())->toBeGreaterThan(10)
        ->and(\App\Models\Programme::whereHas('studyCategory', fn ($q) => $q->where('code', 'NCE'))->count())->toBeGreaterThan(0)
        ->and(\App\Models\Programme::whereHas('studyCategory', fn ($q) => $q->where('code', 'DEG'))->count())->toBeGreaterThan(0);

    expect(Student::count())->toBeGreaterThan(200);
    foreach ([100, 200, 300, 400] as $level) {
        expect(Student::where('current_level', $level)->count())->toBeGreaterThan(0);
    }
    $finalist = Student::where('current_level', 400)->whereNotNull('matric_number')->first();
    expect($finalist->matric_number)->toContain('/2023/');

    // Finished semesters have results; in the running one only final-year sheets have scores, still going through approval.
    expect(CourseResult::count())->toBeGreaterThan(1000)
        ->and(RegistrationForm::where('academic_session_id', $current->id)->count())->toBeGreaterThan(0)
        ->and(CourseResult::where('academic_session_id', $current->id)->where('status', 'published')->count())->toBe(0)
        ->and(\App\Models\ResultSheet::where('academic_session_id', $current->id)->whereHas('stage', fn ($q) => $q->where('name', 'Senate'))->exists())->toBeTrue()
        ->and(\App\Models\CarryoverWaiver::active()->count())->toBeGreaterThan(0);

    // Fees: past sessions paid off, this session a mix; invoice totals match their payments.
    expect(Invoice::where('academic_session_id', '!=', $current->id)->where('status', '!=', 'paid')->count())->toBe(0)
        ->and(Invoice::where('academic_session_id', $current->id)->distinct()->pluck('status')->sort()->values()->all())->toBe(['paid', 'partial', 'unpaid']);
    Invoice::with('payments')->get()->each(fn ($i) => expect(round((float) $i->amount_paid, 2))->toBe(round((float) $i->payments->where('status', 'successful')->sum('amount'), 2)));
    expect(Payment::count())->toBeGreaterThan(0);

    expect(BedAllocation::count())->toBeGreaterThan(0)
        ->and(LibraryLoan::whereNull('returned_at')->count())->toBeGreaterThan(0);

    // Past scores respect the CA and exam maximums (this session's sheets follow their assessment scheme instead).
    $past = fn () => CourseResult::where('academic_session_id', '!=', $current->id);
    expect($past()->where('ca', '>', \App\Services\ResultService::caMax())->count())->toBe(0)
        ->and($past()->where('exam', '>', \App\Services\ResultService::examMax())->count())->toBe(0);

    // A lecturer can open the score sheet of a course they teach this semester and enter scores.
    $assignment = \App\Models\CourseAssignment::where('academic_session_id', $current->id)->where('semester_type_id', \App\Models\SemesterType::where('sort_order', 1)->value('id'))
        ->whereHas('course', fn ($q) => $q->where('code', 'EDU111'))->firstOrFail();
    $this->actingAs($assignment->user)->get(route('results.open', $assignment->course_id))->assertRedirect();
    $sheet = \App\Models\ResultSheet::where('course_id', $assignment->course_id)->where('academic_session_id', $current->id)->firstOrFail();
    expect($sheet->results()->count())->toBeGreaterThan(10);
    $scores = $sheet->results()->pluck('student_id')->mapWithKeys(fn ($id) => [$id => ['ca' => 20, 'exam' => 50]])->all();
    $this->actingAs($assignment->user)->post(route('results.sheets.save', $sheet), ['scores' => $scores])->assertSessionHasNoErrors();
    expect($sheet->results()->where('total', 70)->count())->toBe(count($scores));

    // This semester's lecture and examination timetables are published with every class placed, and people were told.
    $timetables = \App\Models\Timetable::where('academic_session_id', $current->id)->get();
    expect($timetables->pluck('kind')->sort()->values()->all())->toBe(['exam', 'lecture'])
        ->and($timetables->every(fn ($t) => $t->status === 'published' && ($t->summary['unplaced'] ?? 0) === 0))->toBeTrue()
        ->and(\Illuminate\Support\Facades\DB::table('notifications')->where('data', 'like', '%timetable%')->exists())->toBeTrue();

    // Every sample account signs in with the documented password.
    $bursar = User::role('Bursar')->first();
    expect(Hash::check(DemoStaffSeeder::PASSWORD, $bursar->password))->toBeTrue()
        ->and(Hash::check(DemoStaffSeeder::PASSWORD, Student::first()->user->password))->toBeTrue();
});
