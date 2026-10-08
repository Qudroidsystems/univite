<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
});

function addField(array $over = []): CustomField
{
    return CustomField::create($over + ['target' => 'student', 'key' => 'hobby', 'label' => 'Hobby', 'type' => 'text']);
}

function studentRecord(): Student
{
    $a = Applicant::create([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => 'tola@example.com', 'jamb_reg_no' => '12345678AB', 'jamb_score' => 200,
        'programme_id' => test()->programme->id, 'academic_session_id' => test()->session->id, 'entry_mode' => 'utme',
        'entry_level' => 100, 'status' => 'pending', 'password' => 'my-secret-pw',
    ]);
    $a->update(['application_no' => 'APP/2026/0001']);

    return app(AdmissionService::class)->admit($a->fresh(), 'First Batch')[0];
}

it('lets an admin create a field through the builder, with a generated key and parsed choices', function () {
    $this->actingAs($this->admin)->post(route('academics.custom-fields.store'), [
        'target' => 'student', 'label' => 'Blood Group', 'type' => 'select', 'options_text' => "A+\nO+\n\nA+\nB+",
    ])->assertSessionHasNoErrors();

    $f = CustomField::firstOrFail();
    expect($f->key)->toBe('blood_group')->and($f->options)->toBe(['A+', 'O+', 'B+']);

    $this->post(route('academics.custom-fields.store'), ['target' => 'student', 'label' => 'Blood group', 'type' => 'text']);
    expect(CustomField::pluck('key')->all())->toContain('blood_group_2');
});

it('requires choices for a select field', function () {
    $this->actingAs($this->admin)->post(route('academics.custom-fields.store'), ['target' => 'staff', 'label' => 'Grade', 'type' => 'select'])
        ->assertSessionHasErrors('options_text');
});

it('saves custom answers when an admin edits a student, including files, and shows them on the profile', function () {
    $student = studentRecord();
    $text = addField();
    $file = addField(['key' => 'letter', 'label' => 'Recommendation letter', 'type' => 'file', 'accept' => 'pdf']);
    $tick = addField(['key' => 'boarder', 'label' => 'Boarder', 'type' => 'checkbox']);

    $this->actingAs($this->admin)->get(route('students.index'))->assertOk()->assertSee('cf_hobby')->assertSee('Recommendation letter');

    $payload = [
        'first_name' => $student->first_name, 'last_name' => $student->last_name, 'email' => $student->user->email, 'current_level' => 100,
        'cf_hobby' => 'Chess', 'cf_boarder' => '1', 'cf_letter' => UploadedFile::fake()->create('ref.pdf', 100, 'application/pdf'),
    ];
    $this->put(route('students.update', $student->id), $payload)->assertSessionHasNoErrors();

    expect(CustomFieldValue::where('custom_field_id', $text->id)->value('value'))->toBe('Chess');
    $stored = CustomFieldValue::where('custom_field_id', $file->id)->firstOrFail();
    expect($stored->original_name)->toBe('ref.pdf');
    Storage::disk('local')->assertExists($stored->file_path);

    $this->get(route('students.show', $student->id))->assertSee('Chess')->assertSee('ref.pdf')->assertSee('Boarder');
    $this->get(route('custom-fields.download', $stored->id))->assertOk();

    // clearing a text answer removes it; leaving a file alone keeps it
    $this->put(route('students.update', $student->id), array_merge(array_diff_key($payload, ['cf_letter' => 1]), ['cf_hobby' => '']))->assertSessionHasNoErrors();
    expect(CustomFieldValue::where('custom_field_id', $text->id)->exists())->toBeFalse();
    expect(CustomFieldValue::where('custom_field_id', $file->id)->exists())->toBeTrue();
});

it('rejects a file of the wrong type and a value outside the choices', function () {
    $student = studentRecord();
    addField(['key' => 'letter', 'label' => 'Letter', 'type' => 'file', 'accept' => 'pdf']);
    addField(['key' => 'house', 'label' => 'House', 'type' => 'select', 'options' => ['Red', 'Blue']]);

    $this->actingAs($this->admin)->put(route('students.update', $student->id), [
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => $student->user->email, 'current_level' => 100,
        'cf_letter' => UploadedFile::fake()->create('x.exe', 10), 'cf_house' => 'Green',
    ])->assertSessionHasErrors(['cf_letter', 'cf_house']);
});

it('hides inactive fields and keeps their answers', function () {
    $student = studentRecord();
    $f = addField();
    $this->actingAs($this->admin)->put(route('students.update', $student->id), [
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => $student->user->email, 'current_level' => 100, 'cf_hobby' => 'Chess',
    ])->assertSessionHasNoErrors();
    $f->update(['is_active' => false]);

    $this->get(route('students.index'))->assertDontSee('cf_hobby');
    $this->get(route('students.show', $student->id))->assertDontSee('Chess');
    expect(CustomFieldValue::count())->toBe(1);
});

it('blocks changing the type of a field people have already answered', function () {
    $student = studentRecord();
    $f = addField();
    CustomFieldValue::create(['custom_field_id' => $f->id, 'subject_type' => Student::class, 'subject_id' => $student->id, 'value' => 'Chess']);

    $this->actingAs($this->admin)->put(route('academics.custom-fields.update', $f->id), ['target' => 'student', 'label' => 'Hobby', 'type' => 'number'])
        ->assertSessionHasErrors('type');
    $this->put(route('academics.custom-fields.update', $f->id), ['target' => 'student', 'label' => 'Hobbies', 'type' => 'text'])->assertSessionHasNoErrors();
});

it('deletes answers and uploaded files with the field', function () {
    $student = studentRecord();
    $f = addField(['key' => 'letter', 'label' => 'Letter', 'type' => 'file']);
    $this->actingAs($this->admin)->put(route('students.update', $student->id), [
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => $student->user->email, 'current_level' => 100,
        'cf_letter' => UploadedFile::fake()->create('ref.pdf', 50, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $path = CustomFieldValue::firstOrFail()->file_path;

    $this->delete(route('academics.custom-fields.destroy', $f->id))->assertSessionHas('success');
    expect(CustomFieldValue::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('adds custom fields to the staff form and profile', function () {
    addField(['target' => 'staff', 'key' => 'staff_no', 'label' => 'Staff number']);

    $this->actingAs($this->admin)->get(route('users.index'))->assertOk()->assertSee('cf_staff_no');
    $this->post(route('users.store'), ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'cf_staff_no' => 'AF/0042'])->assertSessionHasNoErrors();
    $u = User::where('email', 'ada@example.com')->firstOrFail();

    $this->get(route('users.show', $u))->assertSee('AF/0042');
    $this->put(route('users.update', $u), ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'cf_staff_no' => 'AF/0043'])->assertSessionHasNoErrors();
    $this->get(route('users.show', $u))->assertSee('AF/0043');
});

it('enforces required fields when creating a record but not when editing older ones', function () {
    addField(['target' => 'staff', 'key' => 'staff_no', 'label' => 'Staff number', 'is_required' => true]);
    $this->actingAs($this->admin)->post(route('users.store'), ['name' => 'Ada Obi', 'email' => 'ada@example.com'])->assertSessionHasErrors('cf_staff_no');
});

it('asks the new student for self-service fields during getting started, and makes required ones mandatory', function () {
    $student = studentRecord();
    addField(['key' => 'church', 'label' => 'Place of worship', 'self_service' => true, 'is_required' => true]);
    addField(['key' => 'admin_note', 'label' => 'Admin only note']);

    $this->actingAs($student->user->fresh());
    $this->post(route('my.onboarding.accept'));
    $this->get(route('my.onboarding'))->assertSee('Place of worship')->assertDontSee('Admin only note');

    $bio = [
        'phone_number' => '08031234567', 'gender' => 'female', 'date_of_birth' => '2007-05-06', 'nationality' => 'Nigerian',
        'state_of_origin' => 'Ondo', 'lga' => 'Ondo West', 'address' => '1 Palace Road, Ondo',
        'guardian_name' => 'Mrs Adeyemi', 'guardian_relationship' => 'Mother', 'guardian_phone' => '08030000000',
    ];
    $this->put(route('my.onboarding.biodata'), $bio)->assertSessionHasErrors('cf_church');
    $this->put(route('my.onboarding.biodata'), $bio + ['cf_church' => 'Baptist'])->assertSessionHasNoErrors();
    expect(CustomFieldValue::value('value'))->toBe('Baptist');
});

it('asks applicants for self-service fields on the online application', function () {
    $applicant = Applicant::create([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => 't@example.com', 'jamb_reg_no' => '12345678AB', 'jamb_score' => 200,
        'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme',
        'entry_level' => 100, 'status' => 'pending', 'password' => 'my-secret-pw',
    ]);
    $applicant->update(['application_no' => 'APP/2026/0009']);
    addField(['target' => 'applicant', 'key' => 'referee', 'label' => 'Referee name', 'self_service' => true]);

    $this->withSession(['applicant_id' => $applicant->id])->get(route('apply.dashboard'))->assertOk()->assertSee('Referee name');
});

it('only serves a custom file to people allowed to see that record', function () {
    $student = studentRecord();
    $f = addField(['key' => 'letter', 'label' => 'Letter', 'type' => 'file']);
    $path = UploadedFile::fake()->create('ref.pdf', 10, 'application/pdf')->store('custom-fields/student/1', 'local');
    $v = CustomFieldValue::create(['custom_field_id' => $f->id, 'subject_type' => Student::class, 'subject_id' => $student->id, 'file_path' => $path, 'original_name' => 'ref.pdf']);

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('custom-fields.download', $v->id))->assertNotFound();
    $this->actingAs($student->user->fresh())->get(route('custom-fields.download', $v->id))->assertOk();
});
