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
    expect(Student::count())->toBeGreaterThan(200);
    foreach ([100, 200, 300, 400] as $level) {
        expect(Student::where('current_level', $level)->count())->toBeGreaterThan(0);
    }
    $finalist = Student::where('current_level', 400)->whereNotNull('matric_number')->first();
    expect($finalist->matric_number)->toStartWith('2023/');

    // Finished semesters have results; the running one has registrations but no results.
    expect(CourseResult::count())->toBeGreaterThan(1000)
        ->and(RegistrationForm::where('academic_session_id', $current->id)->count())->toBeGreaterThan(0)
        ->and(CourseResult::whereHas('sheet', fn ($q) => $q->where('academic_session_id', $current->id))->count())->toBe(0);

    // Fees: past sessions paid off, this session a mix; invoice totals match their payments.
    expect(Invoice::where('academic_session_id', '!=', $current->id)->where('status', '!=', 'paid')->count())->toBe(0)
        ->and(Invoice::where('academic_session_id', $current->id)->distinct()->pluck('status')->sort()->values()->all())->toBe(['paid', 'partial', 'unpaid']);
    Invoice::with('payments')->get()->each(fn ($i) => expect(round((float) $i->amount_paid, 2))->toBe(round((float) $i->payments->where('status', 'successful')->sum('amount'), 2)));
    expect(Payment::count())->toBeGreaterThan(0);

    expect(BedAllocation::count())->toBeGreaterThan(0)
        ->and(LibraryLoan::whereNull('returned_at')->count())->toBeGreaterThan(0);

    // Every sample account signs in with the documented password.
    $bursar = User::role('Bursar')->first();
    expect(Hash::check(DemoStaffSeeder::PASSWORD, $bursar->password))->toBeTrue()
        ->and(Hash::check(DemoStaffSeeder::PASSWORD, Student::first()->user->password))->toBeTrue();
});
