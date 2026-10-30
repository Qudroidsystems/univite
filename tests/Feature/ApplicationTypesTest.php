<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\ApplicationType;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\StudyCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $this->pde = StudyCategory::create(['name' => 'PDE', 'code' => 'PDE', 'duration_years' => 1, 'level_format' => 'PDE {N}']);
    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Educational Foundations', 'code' => 'EDF']);
    $this->degree = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->pdeProg = Programme::create(['department_id' => $dept->id, 'name' => 'Postgraduate Diploma in Education', 'code' => 'PDE', 'degree_type' => 'PDE', 'duration_years' => 1, 'study_category_id' => $this->pde->id]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    foreach (['open' => '1', 'session_id' => (string) $this->session->id, 'fee' => '2000', 'min_utme_score' => '150'] as $k => $v) {
        Setting::put("application.$k", $v);
    }
});

function typeApply(array $over = []): \Illuminate\Testing\TestResponse
{
    return test()->post(route('apply.store'), array_merge([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => 'tola@example.com', 'phone_number' => '08031234567',
        'entry_mode' => 'utme', 'jamb_score' => 210, 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ], $over));
}

it('lets applicants choose an open application type with its own fee and programmes', function () {
    $this->actingAs($this->admin)->post(route('applicants.types.store'), ['name' => 'PDE Application', 'study_category_id' => $this->pde->id, 'fee' => 15000,
        'needs_jamb' => 0, 'instructions' => 'Bring your degree certificate.', 'is_active' => 1])->assertSessionHasNoErrors();
    $this->post(route('applicants.types.store'), ['name' => 'NCE Part-Time', 'closes_on' => now()->subDay()->toDateString(), 'needs_jamb' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
    $this->get(route('applicants.types.index'))->assertOk()->assertSee('PDE Application')->assertSee('Not open now');
    auth()->logout();

    $type = ApplicationType::where('name', 'PDE Application')->sole();
    $this->get(route('apply.register'))->assertOk()->assertSee('What are you applying for?')->assertSee('PDE Application')->assertDontSee('NCE Part-Time');

    typeApply(['programme_id' => $this->pdeProg->id])->assertSessionHasErrors('application_type_id');
    typeApply(['application_type_id' => $type->id, 'programme_id' => $this->degree->id])->assertSessionHasErrors('programme_id');
    typeApply(['application_type_id' => $type->id, 'programme_id' => $this->pdeProg->id])->assertSessionHasNoErrors()->assertRedirect(route('apply.dashboard'));

    $applicant = Applicant::sole();
    expect($applicant->application_type_id)->toBe($type->id)->and($applicant->jamb_reg_no)->toBeNull();
    $invoice = Invoice::where('payable_type', Applicant::class)->sole();
    expect((float) $invoice->total)->toBe(15000.0)->and($invoice->title)->toContain('PDE Application');
});

it('keeps who decided and a message for the applicant', function () {
    $applicant = Applicant::create(['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com', 'programme_id' => $this->degree->id,
        'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending', 'application_no' => 'APP/2026/0001']);

    $this->actingAs($this->admin)->post(route('applicants.reject', $applicant->id), ['message' => 'You may apply for PDE instead.'])->assertSessionHas('success');
    $applicant->refresh();
    expect($applicant->status)->toBe('rejected')->and($applicant->decided_by)->toBe($this->admin->id)->and($applicant->decision_message)->toBe('You may apply for PDE instead.');
    $this->get(route('applicants.show', $applicant->id))->assertOk()->assertSee('by ' . $this->admin->name)->assertSee('You may apply for PDE instead.');
    $this->get(route('applicants.index'))->assertOk()->assertSee('You may apply for PDE instead.');
});
