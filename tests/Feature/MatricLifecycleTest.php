<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\MatricEvent;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\MatricService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Setting::put('matric.auto_issue', '0');   // staff generate in batches in these tests
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept    = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
});

function clearedStudent(string $last, string $jamb): Student
{
    $applicant = Applicant::create([
        'first_name' => 'Ada', 'last_name' => $last, 'email' => strtolower($last) . '@example.com', 'jamb_reg_no' => $jamb,
        'programme_id' => test()->programme->id, 'academic_session_id' => test()->session->id, 'entry_mode' => 'utme',
        'entry_level' => 100, 'status' => 'pending', 'password' => 'secret-pass',
    ]);
    $applicant->update(['application_no' => 'APP/2026/' . str_pad((string) $applicant->id, 4, '0', STR_PAD_LEFT)]);
    $student = app(AdmissionService::class)->admit($applicant->fresh())[0];
    $student->update(['cleared_at' => now()]);   // clearance itself is tested elsewhere

    return $student->fresh();
}

it('hands out numbers alphabetically by surname in a batch', function () {
    $zed = clearedStudent('Zed', '11111111AA');
    $abe = clearedStudent('Abe', '22222222BB');

    $this->actingAs($this->admin)->post(route('matric.generate'))->assertSessionHas('success');

    expect($abe->fresh()->matric_number)->toBe('2026/BED-BIO/0001')->and($zed->fresh()->matric_number)->toBe('2026/BED-BIO/0002')
        ->and($zed->fresh()->user->username)->toBe('2026/BED-BIO/0002');
    $this->post(route('matric.generate'))->assertSessionHas('error');
});

it('generates only for ticked students', function () {
    $a = clearedStudent('Abe', '11111111AA');
    $b = clearedStudent('Bola', '22222222BB');

    $this->actingAs($this->admin)->post(route('matric.generate'), ['student_ids' => [$b->id]]);

    expect($a->fresh()->matric_number)->toBeNull()->and($b->fresh()->matric_number)->toBe('2026/BED-BIO/0001');
});

it('does not number students who are not cleared', function () {
    $s = clearedStudent('Abe', '11111111AA');
    $s->update(['cleared_at' => null]);

    $this->actingAs($this->admin)->post(route('matric.generate'))->assertSessionHas('error');
    expect($s->fresh()->matric_number)->toBeNull();
});

it('assigns a special number and refuses duplicates or uncleared students', function () {
    $a = clearedStudent('Abe', '11111111AA');
    $b = clearedStudent('Bola', '22222222BB');
    $this->actingAs($this->admin);

    $this->post(route('matric.special', $a->id), ['matric_number' => 'VC/001'])->assertSessionHas('success');
    expect($a->fresh()->matric_number)->toBe('VC/001')->and($a->fresh()->user->username)->toBe('VC/001');

    $this->post(route('matric.special', $b->id), ['matric_number' => 'VC/001'])->assertSessionHas('error', fn ($m) => str_contains($m, 'already taken'));
    $this->post(route('matric.special', $b->id), ['matric_number' => 'has space'])->assertSessionHas('error');

    $b->update(['cleared_at' => null]);
    $this->post(route('matric.special', $b->id), ['matric_number' => 'VC/002'])->assertSessionHas('error', fn ($m) => str_contains($m, 'not fully cleared'));
});

it('applies numbers made elsewhere from a file and reports rows it cannot use', function () {
    $a = clearedStudent('Abe', '11111111AA');
    $b = clearedStudent('Bola', '22222222BB');
    $csv = "identifier,matric_number\n{$a->applicant->application_no},AFU/2026/0001\n22222222bb,AFU/2026/0002\nnobody@example.com,AFU/2026/0003\n{$a->applicant->application_no},AFU/2026/0009\n";
    $file = UploadedFile::fake()->createWithContent('numbers.csv', $csv);

    $this->actingAs($this->admin)->post(route('matric.import'), ['file' => $file])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2 matric number(s) applied') && str_contains($m, 'no student found') && str_contains($m, 'already has'));

    expect($a->fresh()->matric_number)->toBe('AFU/2026/0001')->and($b->fresh()->matric_number)->toBe('AFU/2026/0002');
});

it('cancels a number for good, restores it, and never reissues a cancelled number', function () {
    $a = clearedStudent('Abe', '11111111AA');
    $this->actingAs($this->admin)->post(route('matric.generate'));
    $number = $a->fresh()->matric_number;

    $this->post(route('matric.cancel', $a->id), [])->assertSessionHasErrors('reason');
    $this->post(route('matric.cancel', $a->id), ['reason' => 'Issued in error'])->assertSessionHas('success');
    expect($a->fresh()->matric_number)->toBeNull()->and($a->fresh()->user->username)->toBe($a->applicant->application_no);

    // a new student does not get the cancelled number
    $b = clearedStudent('Bola', '22222222BB');
    $this->post(route('matric.generate'), ['student_ids' => [$b->id]]);
    expect($b->fresh()->matric_number)->not->toBe($number);

    $this->post(route('matric.restore', $a->id))->assertSessionHas('success');
    expect($a->fresh()->matric_number)->toBe($number);
    $this->post(route('matric.restore', $a->id))->assertSessionHas('error');
});

it('undoes a batch so the numbers can be used again', function () {
    $a = clearedStudent('Abe', '11111111AA');
    $b = clearedStudent('Bola', '22222222BB');
    $this->actingAs($this->admin)->post(route('matric.generate'));
    $batch = MatricEvent::where('student_id', $a->id)->value('batch');
    expect($b->fresh()->matric_number)->toBe('2026/BED-BIO/0002');

    $this->post(route('matric.undo'), ['batch' => $batch])->assertSessionHas('success', fn ($m) => str_contains($m, '2 matric number(s)'));
    expect($a->fresh()->matric_number)->toBeNull()->and($b->fresh()->matric_number)->toBeNull();

    $this->post(route('matric.generate'), ['student_ids' => [$b->id]]);
    expect($b->fresh()->matric_number)->toBe('2026/BED-BIO/0001');   // the freed numbers are used again

    $this->post(route('matric.undo'), ['batch' => 'NOSUCH'])->assertSessionHas('error');
});

it('leaves alone a student whose number changed since the batch', function () {
    $a = clearedStudent('Abe', '11111111AA');
    $b = clearedStudent('Bola', '22222222BB');
    $this->actingAs($this->admin)->post(route('matric.generate'));
    $batch = MatricEvent::where('student_id', $a->id)->value('batch');

    $this->post(route('matric.cancel', $b->id), ['reason' => 'x']);
    $this->post(route('matric.undo'), ['batch' => $batch])->assertSessionHas('success', fn ($m) => str_contains($m, '1 matric number(s)'));

    expect($a->fresh()->matric_number)->toBeNull()->and($b->fresh()->matric_number)->toBeNull();
    // Bola's cancelled number stays reserved, so the next number skips it
    $this->post(route('matric.generate'), ['student_ids' => [$a->id]]);
    expect($a->fresh()->matric_number)->toBe('2026/BED-BIO/0003');
});

it('protects every matric action with its own permission and lists the tabs', function () {
    $s = clearedStudent('Abe', '11111111AA');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('View matric numbers');

    $this->actingAs($viewer)->get(route('matric.index'))->assertOk()->assertSee('Abe');
    $this->get(route('matric.index', ['tab' => 'issued']))->assertOk();
    $this->get(route('matric.index', ['tab' => 'cancelled']))->assertOk();
    $this->post(route('matric.generate'))->assertForbidden();
    $this->post(route('matric.special', $s->id), ['matric_number' => 'X/1'])->assertForbidden();
    $this->post(route('matric.cancel', $s->id), ['reason' => 'x'])->assertForbidden();
    $this->post(route('matric.restore', $s->id))->assertForbidden();
    $this->post(route('matric.undo'), ['batch' => 'A'])->assertForbidden();
    $this->post(route('matric.import'))->assertForbidden();
});

it('saves when matric numbers are issued', function () {
    $this->actingAs($this->admin)->put(route('students.settings.issue'), ['issue_at' => 'admission', 'auto_issue' => 0])->assertSessionHas('success');
    expect(\App\Services\MatricNumberGenerator::issueAt())->toBe('admission')->and(\App\Services\MatricNumberGenerator::autoIssue())->toBeFalse();
    $this->put(route('students.settings.issue'), ['issue_at' => 'whenever'])->assertSessionHasErrors('issue_at');
});
