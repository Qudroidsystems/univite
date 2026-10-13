<?php

use App\Models\Course;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->course = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU101', 'title' => 'Foundations of Education', 'credit_units' => 2, 'level' => 100]);
    $svc = app(\App\Services\AssessmentService::class);
    $scheme = $svc->save(null, ['name' => 'Tests and exam'], [
        ['name' => 'Test', 'code' => 'T1', 'component' => 'ca', 'max_score' => 20],
        ['name' => 'Assignment', 'code' => 'ASG', 'component' => 'ca', 'max_score' => 10],
        ['name' => 'Exam', 'code' => 'EXAM', 'component' => 'exam', 'max_score' => 70],
    ], $this->admin);
    $svc->assign($scheme, 'university', null, null, $this->admin);
    $this->students = collect(['Ada', 'Bola'])->map(function ($n) {
        $s = makeStudent($this, $n);
        RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 2])
            ->courses()->create(['student_id' => $s->id, 'course_id' => $this->course->id, 'credit_units' => 2]);

        return $s;
    });
    \App\Models\ResultStage::query()->delete();
    $this->lecturer = makeStaff($this, 'Grace Lecturer');
    $this->lecturer->update(['password' => 'phone-pass-1']);
    \App\Models\CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    $this->token = $this->postJson('/api/v1/auth/login', ['login' => $this->lecturer->email, 'password' => 'phone-pass-1', 'device_name' => 'Pixel'])->json('data.token');
    $this->sheet = $this->withToken($this->token)->postJson("/api/v1/teaching/courses/{$this->course->id}/open")->assertOk()->json('data');
});

it('writes and reads back an Excel file', function () {
    $data = Xlsx::write([['Matric Number', 'Name', 'T1 (20)'], ['A/1', 'Ada "Q" Student & co', 15.5], ['A/2', 'Bola', null]], 'EDU101', ['limits' => [2 => [0, 20]], 'notes' => ['Fill it in.']]);
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($path, $data);
    expect(Xlsx::read($path))->toBe([['Matric Number', 'Name', 'T1 (20)'], ['A/1', 'Ada "Q" Student & co', 15.5], ['A/2', 'Bola']]);
    expect(Xlsx::col(0))->toBe('A')->and(Xlsx::col(27))->toBe('AB');
});

it('lets a lecturer download the sheet as Excel, fill it in and upload it from the app', function () {
    $res = $this->withToken($this->token)->get("/api/v1/teaching/sheets/{$this->sheet['id']}/file")->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($res->headers->get('Content-Disposition'))->toContain('EDU101_2026_2027_First_Semester_scores.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($path, $res->getContent());
    $rows = Xlsx::read($path);
    expect($rows[0])->toBe(['Matric Number', 'Name', 'T1 (20)', 'ASG (10)', 'EXAM (70)'])->and(count($rows))->toBe(3);

    // fill in: one good row, one mark out of range
    $filled = [$rows[0], [$rows[1][0], $rows[1][1], 15, 8, 50], [$rows[2][0], $rows[2][1], 25, 5, 40]];
    file_put_contents($path, Xlsx::write($filled, 'EDU101'));
    $this->withToken($this->token)->post("/api/v1/teaching/sheets/{$this->sheet['id']}/upload", ['file' => new UploadedFile($path, 'EDU101_scores.xlsx', null, null, true)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('meta.read', 2)->assertJsonPath('meta.problems.0', fn ($p) => str_contains($p, 'from 0 to 20'))
        ->assertJsonPath('data.missing', 1);
    $sheet = $this->withToken($this->token)->getJson("/api/v1/teaching/sheets/{$this->sheet['id']}")->json('data');
    expect(collect($sheet['rows'])->firstWhere('matric_number', $rows[1][0])['total'])->toEqual(73);

    // CSV works too, and other files are refused
    $csv = $this->withToken($this->token)->get("/api/v1/teaching/sheets/{$this->sheet['id']}/file?format=csv")->assertOk()->getContent();
    expect($csv)->toContain('"Matric Number","Name","T1 (20)","ASG (10)","EXAM (70)"')->toContain(',15,8,50');
    $this->withToken($this->token)->post("/api/v1/teaching/sheets/{$this->sheet['id']}/upload", ['file' => UploadedFile::fake()->create('scores.pdf', 10)], ['Accept' => 'application/json'])->assertUnprocessable();

    // another lecturer cannot download or upload
    $other = makeStaff($this, 'Other Lecturer');
    $other->update(['password' => 'phone-pass-1']);
    $this->app['auth']->forgetGuards();
    $t2 = $this->postJson('/api/v1/auth/login', ['login' => $other->email, 'password' => 'phone-pass-1', 'device_name' => 'x'])->json('data.token');
    $this->app['auth']->forgetGuards();
    $this->withToken($t2)->get("/api/v1/teaching/sheets/{$this->sheet['id']}/file")->assertNotFound();
});

it('gives the same Excel file on the portal and reads it back on upload', function () {
    $this->app['auth']->forgetGuards();
    $res = $this->actingAs($this->lecturer)->get(route('results.sheets.template', $this->sheet['id']))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($path, $res->getContent());
    $rows = Xlsx::read($path);
    $rows[1] = [$rows[1][0], $rows[1][1], 10, 10, 60];
    file_put_contents($path, Xlsx::write($rows, 'EDU101'));
    $this->actingAs($this->lecturer)->post(route('results.sheets.upload', $this->sheet['id']), ['file' => new UploadedFile($path, 'scores.xlsx', null, null, true)])
        ->assertSessionHas('success');
    expect(ResultSheet::find($this->sheet['id'])->results()->whereNotNull('total')->count())->toBe(1);
    $this->actingAs($this->lecturer)->get(route('results.sheets.show', $this->sheet['id']))->assertOk()->assertSee('Download Excel to fill in');
});
