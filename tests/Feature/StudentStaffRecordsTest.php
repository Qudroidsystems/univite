<?php

use App\Models\AcademicSession;
use App\Models\AdmissionDeferral;
use App\Models\CourseOption;
use App\Models\PeopleImport;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\StudentTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    Storage::fake('public');
});

it('records NIN, JAMB number, religion, option and referees, and shows them on the profile', function () {
    $s = makeStudent($this, 'Ada');
    $opt = CourseOption::create(['programme_id' => $this->prog->id, 'name' => 'Acting']);
    $this->actingAs($this->admin)->put(route('students.update', $s->id), [
        'first_name' => 'Ada', 'last_name' => 'Student', 'email' => $s->user->email, 'current_level' => 100,
        'jamb_reg_no' => '12345678AB', 'nin' => '12345678901', 'religion' => 'christianity', 'blood_group' => 'O+', 'genotype' => 'AA', 'course_option_id' => $opt->id,
    ])->assertSessionHasNoErrors();
    $this->put(route('students.referees', $s->id), ['referees' => [1 => ['name' => 'Dr Bello', 'phone' => '0803'], 2 => ['name' => '']]])->assertSessionHas('success');

    $this->get(route('students.show', $s->id))->assertOk()->assertSee('12345678AB')->assertSee('12345678901')->assertSee('Acting')->assertSee('Dr Bello')->assertSee('O+ · AA');
    // the NIN must have 11 digits, and the option must belong to the student's programme
    $other = CourseOption::create(['programme_id' => $this->prog2->id, 'name' => 'Analytical']);
    $this->put(route('students.update', $s->id), ['first_name' => 'Ada', 'last_name' => 'Student', 'email' => $s->user->email, 'current_level' => 100, 'nin' => '123', 'course_option_id' => $other->id])
        ->assertSessionHasErrors(['nin', 'course_option_id']);

    // hiding the NIN in Settings → Students hides it on the profile
    $this->put(route('students.profile-settings.update'), ['allow_profile_update' => 1, 'show_nin' => 0, 'require_nin' => 0, 'show_jamb' => 1, 'referees' => '2'])->assertSessionHas('success');
    $this->get(route('students.show', $s->id))->assertDontSee('12345678901');
});

it('lets students update their own profile only when the school allows it', function () {
    $s = makeStudent($this, 'Bisi');
    $this->actingAs($s->user)->get(route('my.profile'))->assertOk()->assertSee('Referee 1');
    $this->put(route('my.profile.update'), ['phone_number' => '08011112222', 'religion' => 'islam', 'nin' => '10987654321', 'referees' => [1 => ['name' => 'Mrs Ojo']],
        'signature' => UploadedFile::fake()->image('sig.png', 300, 100)])->assertSessionHas('success');
    $s->refresh();
    expect($s->phone_number)->toBe('08011112222')->and($s->religion)->toBe('islam')->and($s->nin)->toBe('10987654321')->and($s->referees()->first()->name)->toBe('Mrs Ojo');
    Storage::disk('public')->assertExists($s->signature);

    \App\Models\Setting::put('students.allow_profile_update', '0');
    $this->put(route('my.profile.update'), ['phone_number' => '0800'])->assertForbidden();
    $this->get(route('my.profile'))->assertOk()->assertSee('Ask the registry');
});

it('transfers a student to another programme and keeps the history', function () {
    $s = makeStudent($this, 'Chidi', level: 200);
    $this->actingAs($this->admin)->post(route('students.transfer', $s->id), ['programme_id' => $this->prog2->id, 'level' => 200, 'reason' => 'Change of course approved by Senate'])
        ->assertSessionHas('success');
    expect($s->fresh()->programme_id)->toBe($this->prog2->id)
        ->and(StudentTransfer::where('student_id', $s->id)->first())->from_programme_id->toBe($this->prog->id);
    $this->get(route('students.show', $s->id))->assertSee('Change of course approved by Senate');
    $this->post(route('students.transfer', $s->id), ['programme_id' => $this->prog2->id, 'level' => 900, 'reason' => 'Wrong level'])->assertSessionHas('error');

    $lecturer = makeStaff($this, 'No Rights');
    $this->actingAs($lecturer)->post(route('students.transfer', $s->id), ['programme_id' => $this->prog->id, 'level' => 200, 'reason' => 'Not allowed'])->assertForbidden();
});

it('defers a new student to a later session and brings them back with that entry session', function () {
    $later = AcademicSession::create(['name' => '2027/2028']);
    $s = makeStudent($this, 'Dayo', extra: ['matric_number' => null]);
    $this->actingAs($this->admin)->post(route('students.defer', $s->id), ['to_session_id' => $later->id, 'reason' => 'Medical grounds'])->assertSessionHas('success');
    expect($s->fresh()->status)->toBe('deferred');
    $this->get(route('students.deferrals'))->assertOk()->assertSee('Dayo')->assertSee('2027/2028');
    $this->post(route('students.defer', $s->id), ['to_session_id' => $later->id, 'reason' => 'Again please'])->assertSessionHas('error');

    $d = AdmissionDeferral::firstOrFail();
    $this->post(route('students.deferrals.resume', $d))->assertSessionHas('success');
    $s->refresh();
    expect($s->status)->toBe('active')->and($s->academic_session_id)->toBe($later->id)->and($d->fresh()->status)->toBe('resumed');
});

it('imports students from the template, skips people already there, reports problems and can be undone', function () {
    Storage::fake('local');
    $existing = makeStudent($this, 'Eze');
    $csv = "Surname,First name,Email,Programme code,Entry session,Matric number,Current level,NIN,Date of birth\n"
        . "Okafor,Ngozi,ngozi@example.com,BED-CHE,2026/2027,AFE/2023/0101,200,12345678901,17/05/2004\n"
        . "Bad,Row,not-an-email,BED-CHE,2026/2027,,,,\n"
        . "Lost,Prog,lost@example.com,NOPE,2026/2027,,,,\n"
        . "Eze,Student,{$existing->user->email},BED-CHE,2026/2027,,,,\n";
    $this->actingAs($this->admin)->get(route('imports.students'))->assertOk()->assertSee('Download template');
    expect($this->get(route('imports.people.template', 'students'))->assertOk()->streamedContent())->toContain('Surname,"First name"');
    $this->post(route('imports.people.store', 'students'), ['file' => UploadedFile::fake()->createWithContent('students.csv', $csv)])->assertSessionHas('success');
    $im = PeopleImport::firstOrFail();
    expect($im->report['ready'])->toBe(1)->and($im->report['existing'])->toBe(1)->and($im->report['problems'])->toHaveCount(2);

    $this->post(route('imports.people.import', ['students', $im]))->assertSessionHas('success');
    $new = Student::where('matric_number', 'AFE/2023/0101')->firstOrFail();
    expect($new->current_level)->toBe(200)->and($new->nin)->toBe('12345678901')->and($new->date_of_birth->format('Y-m-d'))->toBe('2004-05-17')
        ->and($new->user->hasRole('Student'))->toBeTrue();

    $this->post(route('imports.people.undo', ['students', $im]))->assertSessionHas('success');
    expect(Student::where('matric_number', 'AFE/2023/0101')->exists())->toBeFalse()->and(User::where('email', 'ngozi@example.com')->exists())->toBeFalse();
});

it('imports staff with file and IPPIS numbers', function () {
    Storage::fake('local');
    $csv = "Title,Surname,First name,Email,Staff number,File number,IPPIS number,Department code,Grade level\n"
        . "Dr,Edafe,John,j.edafe@example.edu.ng,SP/0451,DSSET/ADE/2425,123456,SED,13\n";
    $this->actingAs($this->admin)->post(route('imports.people.store', 'staff'), ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv)])->assertSessionHas('success');
    $this->post(route('imports.people.import', ['staff', PeopleImport::firstOrFail()]))->assertSessionHas('success');
    $p = StaffProfile::where('staff_number', 'SP/0451')->firstOrFail();
    expect($p->file_number)->toBe('DSSET/ADE/2425')->and($p->ippis_number)->toBe('123456')->and($p->department_id)->toBe($this->dept->id)->and($p->title)->toBe('dr');
    $this->get(route('staff.show', $p))->assertOk()->assertSee('DSSET/ADE/2425');
});
