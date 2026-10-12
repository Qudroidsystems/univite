<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Confirm a reversal the way the preview page does: same mode, the preview's fingerprint, a reason and the tick box. */
function confirmReversal($test, \App\Models\OperationBatch $op, ?string $mode = null, string $reason = 'Issued with the wrong format by mistake.')
{
    $plan = \App\Support\Reversals::for($op)->plan($op, $mode);

    return $test->post(route('operations.reverse.run', $op), ['mode' => $plan->mode, 'fingerprint' => $plan->fingerprint(), 'reason' => $reason, 'confirm' => 1]);
}

/** A small university for the campus-service tests: two faculties, a programme in each, the current session and the admin. */
function campusWorld($test): void
{
    $test->seed(\Database\Seeders\DatabaseSeeder::class);
    $test->admin = \App\Models\User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $test->session = \App\Models\AcademicSession::create(['name' => '2026/2027', 'is_current' => true, 'start_date' => '2026-10-01', 'end_date' => '2027-09-30']);
    $test->faculty = \App\Models\Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $test->faculty2 = \App\Models\Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $test->dept = \App\Models\Department::create(['faculty_id' => $test->faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $test->dept2 = \App\Models\Department::create(['faculty_id' => $test->faculty2->id, 'name' => 'Chemistry', 'code' => 'CHM']);
    $test->prog = \App\Models\Programme::create(['department_id' => $test->dept->id, 'name' => 'B.Ed. Chemistry Education', 'code' => 'BED-CHE', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $test->prog2 = \App\Models\Programme::create(['department_id' => $test->dept2->id, 'name' => 'B.Sc. Chemistry', 'code' => 'BSC-CHM', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
}

function makeStudent($test, string $first, string $gender = 'female', int $level = 100, ?\App\Models\Programme $programme = null, array $extra = []): \App\Models\Student
{
    static $n = 0;
    $n++;
    $user = \App\Models\User::create(['name' => "{$first} Student", 'email' => strtolower($first) . $n . '@students.example.com', 'password' => 'secret-pass', 'gender' => $gender]);

    return \App\Models\Student::create($extra + [
        'user_id' => $user->id, 'matric_number' => sprintf('AFU/2026/%04d', $n), 'first_name' => $first, 'last_name' => 'Student', 'gender' => $gender,
        'programme_id' => ($programme ?? $test->prog)->id, 'academic_session_id' => $test->session->id, 'entry_mode' => 'utme',
        'entry_level' => 100, 'current_level' => $level, 'status' => 'active',
    ]);
}

function makeStaff($test, string $name, ?\App\Models\Department $dept = null, array $permissions = []): \App\Models\User
{
    static $n = 0;
    $n++;
    $user = \App\Models\User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@staff.example.com', 'password' => 'secret-pass']);
    \App\Models\StaffProfile::create(['user_id' => $user->id, 'department_id' => ($dept ?? $test->dept)->id, 'staff_number' => sprintf('SP/%04d', $n)]);
    if ($permissions) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}
