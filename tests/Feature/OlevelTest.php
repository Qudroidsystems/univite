<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OlevelRequirement;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\StudentOlevelSitting;
use App\Models\User;
use App\Services\AdmissionService;
use App\Support\MobileAppSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Microbiology', 'code' => 'MCB']);
    $this->programme = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.Sc. Microbiology', 'code' => 'BSC-MCB', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $c = Course::create(['department_id' => $this->dept->id, 'code' => 'MCB 101', 'title' => 'Intro Microbiology', 'credit_units' => 15, 'level' => 100]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $c->id, 'level' => 100, 'semester_order' => 1, 'status' => 'compulsory']);
    $this->course = $c;

    $a = Applicant::create([
        'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi@example.com', 'jamb_reg_no' => '55555555AB', 'jamb_score' => 230,
        'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending',
    ]);
    $a->update(['application_no' => 'APP/2026/0009']);
    $this->student = app(AdmissionService::class)->admit($a->fresh(), 'First Batch')->student;
    $this->student->update(['needs_onboarding' => false, 'matric_number' => '2026/MCB/0001']);

    $this->actingAs($this->admin)->put(route('olevel.requirements.save'), [
        'scope' => 'department', 'id' => $this->dept->id, 'is_active' => 1, 'min_credits' => 5, 'max_sittings' => 2, 'optional_needed' => 1, 'applies_to' => 'entry',
        'subjects' => [
            ['subject' => 'English Language', 'min_grade' => 'C6', 'kind' => 'required'],
            ['subject' => 'Mathematics', 'min_grade' => 'C6', 'kind' => 'required'],
            ['subject' => 'Biology', 'min_grade' => 'C6', 'kind' => 'required'],
            ['subject' => 'Chemistry', 'min_grade' => 'C6', 'kind' => 'optional'],
            ['subject' => 'Physics', 'min_grade' => 'C6', 'kind' => 'optional'],
        ],
    ])->assertSessionHas('success');
});

function asStudentO(): \Illuminate\Foundation\Testing\TestCase
{
    return test()->actingAs(test()->student->user->fresh());
}

function sittingData(array $grades, array $extra = []): array
{
    $rows = [];
    foreach ($grades as $s => $g) {
        $rows[] = ['subject' => $s, 'grade' => $g];
    }

    return $extra + ['exam_type' => 'waec', 'exam_year' => 2024, 'exam_number' => '4250101001', 'subjects' => $rows];
}

it('saves the department requirement and describes it', function () {
    $req = OlevelRequirement::with('subjects')->firstOrFail();
    expect($req->subjects)->toHaveCount(5)
        ->and($req->summary())->toContain('English Language (C6)')->toContain('1 of Chemistry (C6), Physics (C6)')->toContain('5 credits in at most 2 sittings');
    $this->get(route('olevel.requirements', ['scope' => 'department', 'id' => $this->dept->id]))->assertOk()->assertSee('Microbiology');
});

it('blocks registration at the admission level until O Level results are confirmed and meet the requirement', function () {
    asStudentO()->get(route('my.registration'))->assertOk()->assertSee('Submit your O Level results');

    // the student enters a sitting without an upload: cannot submit while an upload is required
    $this->post(route('my.olevel.store'), sittingData(['English Language' => 'C5', 'Mathematics' => 'B3', 'Biology' => 'C6', 'Chemistry' => 'C4', 'Economics' => 'B2'], ['submit' => 1]))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Upload the scanned result'));
    $sitting = StudentOlevelSitting::firstOrFail();
    expect($sitting->status)->toBe('draft')->and($sitting->results)->toHaveCount(5);

    $this->put(route('my.olevel.update', $sitting->id), sittingData(['English Language' => 'C5', 'Maths' => 'B3', 'Biology' => 'C6', 'Chemistry' => 'C4', 'Economics' => 'B2'], [
        'document' => UploadedFile::fake()->create('waec.pdf', 120, 'application/pdf'), 'submit' => 1,
    ]))->assertSessionHas('success');
    expect($sitting->fresh()->status)->toBe('submitted');
    $this->get(route('my.registration'))->assertSee('waiting for the school to confirm');

    // staff confirm with the student's grades: requirement met, registration opens
    $this->actingAs($this->admin)->get(route('olevel.index'))->assertOk()->assertSee('Ngozi');
    $this->get(route('olevel.show', $sitting->id))->assertOk()->assertSee('Student entered');
    $this->post(route('olevel.confirm', $sitting->id), ['source' => 'student'])->assertSessionHas('success', fn ($m) => str_contains($m, 'Meets the O Level requirement'));
    expect($sitting->fresh()->results->pluck('confirmed_grade', 'subject')->all())->toMatchArray(['Maths' => 'B3', 'Chemistry' => 'C4']);

    asStudentO()->get(route('my.registration'))->assertDontSee('Submit your O Level results')->assertDontSee('do not meet');
    $this->post(route('my.registration.save'), ['courses' => [$this->course->id], 'submit' => 1])->assertSessionHas('success');
});

it('reads the uploaded result with the AI service and lets staff keep the grades read from it', function () {
    app(MobileAppSettings::class)->save(['ai_provider' => 'anthropic', 'ai_api_key' => 'test-key', 'ai_daily_limit' => 1]);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
        'exam_type' => 'waec', 'exam_year' => 2024, 'exam_number' => '4250101001', 'candidate_name' => 'EZE NGOZI', 'readable' => true,
        'subjects' => [['subject' => 'English Language', 'grade' => 'C6'], ['subject' => 'Mathematics', 'grade' => 'D7'], ['subject' => 'Biology', 'grade' => 'C5'],
            ['subject' => 'Physics', 'grade' => 'C6'], ['subject' => 'Economics', 'grade' => 'B3'], ['subject' => 'Civic Education', 'grade' => 'B2']],
    ])]], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 200]])]);

    asStudentO()->post(route('my.olevel.store'), sittingData(['English Language' => 'C6', 'Mathematics' => 'C6', 'Biology' => 'C5', 'Physics' => 'C6', 'Economics' => 'B3'], [
        'document' => UploadedFile::fake()->image('waec.jpg'),
    ]))->assertSessionHas('success', fn ($m) => str_contains($m, 'We read your uploaded result'));
    $sitting = StudentOlevelSitting::with('results')->firstOrFail();
    $maths = $sitting->results->firstWhere('subject', 'Mathematics');
    expect($sitting->extraction_status)->toBe('done')->and($maths->extracted_grade)->toBe('D7')->and($maths->mismatch())->toBeTrue()
        ->and($sitting->results->firstWhere('subject', 'Civic Education')->grade)->toBeNull();
    // reading for the school does not use up the student's own study companion allowance
    expect(app(\App\Services\Ai\AiClient::class)->remaining($this->student->user))->toBe(1);
    $this->get(route('my.olevel'))->assertOk()->assertSee('Read from your upload')->assertSee('D7');
    $this->post(route('my.olevel.submit', $sitting->id))->assertSessionHas('success');

    // staff use what was read from the document: Mathematics D7 fails the requirement
    $this->actingAs($this->admin)->post(route('olevel.confirm', $sitting->id), ['source' => 'extracted'])
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'Mathematics: C6 or better needed (you have D7)'));
    asStudentO()->get(route('my.registration'))->assertSee('do not meet');

    // a second sitting with a credit in Mathematics: two sittings may be combined
    $this->post(route('my.olevel.store'), sittingData(['Mathematics' => 'B3', 'English Language' => 'C4'], ['exam_type' => 'neco', 'exam_number' => 'NECO123',
        'document' => UploadedFile::fake()->create('neco.pdf', 50, 'application/pdf'), 'submit' => 1]));
    $neco = StudentOlevelSitting::where('exam_type', 'neco')->firstOrFail();
    $this->actingAs($this->admin)->post(route('olevel.confirm', $neco->id), ['source' => 'mixed', 'grades' => $neco->results->mapWithKeys(fn ($r) => [$r->id => $r->grade])->all()])
        ->assertSessionHas('success');
    $check = app(\App\Services\Olevel\OlevelService::class)->evaluate($this->student->fresh());
    expect($check->met)->toBeTrue()->and($check->grades['Mathematics'])->toBe('B3')->and($check->sittings)->toHaveCount(2);
});

it('lets staff return a sitting or let the student register meanwhile, and only checks the admission level by default', function () {
    asStudentO()->post(route('my.olevel.store'), sittingData(['English Language' => 'F9'], ['document' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf'), 'submit' => 1]));
    $sitting = StudentOlevelSitting::firstOrFail();
    $this->actingAs($this->admin)->post(route('olevel.reject', $sitting->id), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(route('olevel.reject', $sitting->id), ['note' => 'Upload the full statement of result.'])->assertSessionHas('success');
    expect($sitting->fresh()->status)->toBe('rejected');

    $this->post(route('olevel.override', $this->student->id), ['allow' => 1, 'note' => 'Awaiting NECO result'])->assertSessionHas('success');
    asStudentO()->get(route('my.registration'))->assertDontSee('Submit your O Level results');

    $this->actingAs($this->admin)->post(route('olevel.override', $this->student->id), ['allow' => 0])->assertSessionHas('success');
    $this->student->update(['current_level' => 200]);
    asStudentO()->get(route('my.registration'))->assertDontSee('Submit your O Level results');
});

it('keeps a head of department to their own department', function () {
    $other = Department::create(['faculty_id' => $this->dept->faculty_id, 'name' => 'Botany', 'code' => 'BOT']);
    $hod = User::factory()->create();
    $this->dept->update(['hod_id' => $hod->id]);
    app(\App\Services\Access\HeadRoles::class)->sync($this->dept->fresh());
    asStudentO()->post(route('my.olevel.store'), sittingData(['English Language' => 'C6'], ['document' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf'), 'submit' => 1]));
    $sitting = StudentOlevelSitting::firstOrFail();

    $this->actingAs($hod->fresh())->get(route('olevel.index'))->assertOk()->assertSee('Ngozi');
    $this->get(route('olevel.show', $sitting->id))->assertOk();
    $this->put(route('olevel.requirements.save'), ['scope' => 'department', 'id' => $other->id, 'min_credits' => 5, 'max_sittings' => 2, 'applies_to' => 'entry'])->assertForbidden();
});
