<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Chemistry', 'code' => 'CHM']);
    Course::create(['department_id' => $this->dept->id, 'code' => 'CHM101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
    $this->clerk = User::create(['name' => 'Course Clerk', 'email' => 'clerk@example.com', 'password' => 'secret-pass']);
    $this->clerk->givePermissionTo('View courses');
});

it('draws the sidebar in sections with the account footer, and the search box with the pages the person may open', function () {
    $page = $this->actingAs($this->admin)->get(route('academics.courses.index'))->assertOk();
    $page->assertSee('Academics')->assertSee('Bursary &amp; Finance', false)->assertSee('Campus Life')->assertSee('Administration')
        ->assertSee('Sign Out')->assertSee('spotlight-overlay')->assertSee('Search everything');

    // The current page is marked, and its group is open.
    expect($page->getContent())->toMatch('/class="nav-link active nav-active-child">Courses</');

    $pages = json_decode(preg_replace('/^.*<script type="application\/json" id="spotlight-data">(.*?)<\/script>.*$/s', '$1', $page->getContent()), true)['pages'];
    expect(collect($pages)->pluck('title'))->toContain('Invoices & Payments', 'Payment Settings', 'Courses', 'Users');
});

it('leaves out pages the person may not open, in the sidebar and in the search box', function () {
    $page = $this->actingAs($this->clerk)->get(route('academics.courses.index'))->assertOk();
    $pages = collect(json_decode(preg_replace('/^.*<script type="application\/json" id="spotlight-data">(.*?)<\/script>.*$/s', '$1', $page->getContent()), true)['pages'])->pluck('title');

    expect($pages)->toContain('Courses')->not->toContain('Invoices & Payments')->not->toContain('Users');
    $page->assertDontSee('Bursary &amp; Finance', false)->assertDontSee('Payment Settings');
});

it('searches records the person may see and nothing else', function () {
    $this->actingAs($this->clerk)->getJson(route('search', ['q' => 'chm1']))->assertOk()
        ->assertJsonPath('results.0.title', 'CHM101 General Chemistry')->assertJsonPath('results.0.category', 'Courses');
    expect($this->getJson(route('search', ['q' => 'Course Clerk']))->json('results'))->toBe([]); // no "View users"

    $this->actingAs($this->admin)->getJson(route('search', ['q' => 'Course Clerk']))->assertJsonPath('results.0.category', 'User Accounts');
    expect($this->getJson(route('search', ['q' => 'c']))->json('results'))->toBe([]); // too short
});

it('finds invoices by their reference or a payment reference', function () {
    $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    $invoice = Invoice::create(['kind' => 'fees', 'payable_type' => Student::class, 'payable_id' => 999, 'academic_session_id' => $session->id, 'title' => 'School fees', 'total' => 100, 'amount_paid' => 0, 'status' => 'unpaid', 'reference' => 'INV-2026-000777']);
    $invoice->payments()->create(['reference' => 'PAY-ABCXYZ', 'gateway' => 'paystack', 'amount' => 100, 'status' => 'pending']);

    $this->actingAs($this->admin)->getJson(route('search', ['q' => '000777']))->assertJsonPath('results.0.url', route('invoices.show', $invoice));
    $this->getJson(route('search', ['q' => 'abcxyz']))->assertJsonPath('results.0.url', route('invoices.show', $invoice));
});

it('needs a signed-in person to search', function () {
    $this->getJson(route('search', ['q' => 'chem']))->assertUnauthorized();
});
