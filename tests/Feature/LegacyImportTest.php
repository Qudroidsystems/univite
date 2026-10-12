<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\LegacyBalance;
use App\Models\LegacyImport;
use App\Models\OperationBatch;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\LegacyImportService;
use App\Services\ResultService;
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
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Educational Foundations', 'code' => 'EDF']);
    $prog = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Educational Foundations', 'code' => 'EDF', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $session = AcademicSession::create(['name' => '2019/2020']);
    foreach (['AFE/2016/0001' => 'Ade', 'AFE/2016/0002' => 'Bola'] as $matric => $name) {
        $u = User::create(['name' => "{$name} Student", 'email' => strtolower($name) . '@example.com', 'password' => 'secret-pass']);
        $this->{$name} = Student::create(['user_id' => $u->id, 'first_name' => $name, 'last_name' => 'Student', 'programme_id' => $prog->id, 'academic_session_id' => $session->id, 'entry_mode' => 'utme', 'current_level' => 400, 'entry_level' => 100, 'status' => 'active', 'matric_number' => $matric]);
    }
    Course::create(['department_id' => $dept->id, 'code' => 'GSE 101', 'title' => 'Use of English I', 'credit_units' => 2, 'level' => 100]);
    Course::create(['department_id' => $dept->id, 'code' => 'EDF 111', 'title' => 'History of Education', 'credit_units' => 3, 'level' => 100]);
});

function csvUpload(string $name, array $rows): UploadedFile
{
    $h = fopen('php://temp', 'w+');
    foreach ($rows as $r) {
        fputcsv($h, $r);
    }
    rewind($h);

    return UploadedFile::fake()->createWithContent($name, stream_get_contents($h));
}

it('guesses the columns of an old export, checks every row and imports only the good ones as published results', function () {
    $file = csvUpload('afued-results.csv', [
        ['S/N', 'REG NO', 'SESSION', 'SEM', 'COURSE CODE', 'COURSE TITLE', 'C.U', 'CA', 'EXAM', 'GRADE'],
        [1, 'afe/2016/0001', '2016/17', '1st', 'GSE101', 'Use of English I', 2, 25, 48, ''],          // 73 = A today
        [2, 'AFE/2016/0001', '2016/2017', 'First', 'EDF 111', 'History of Education', 3, 10, 30, 'C'], // 40 is E today; old grade C kept
        [3, 'AFE/2016/0002', '2016-2017', 'Harmattan', 'EDF 111', '', '', 20, 41, ''],
        [4, 'AFE/2016/0002', '2016/2017', '2', 'PHE 102', 'Physical Education', 1, 15, 40, ''],       // course not in the portal
        [5, 'AFE/2016/9999', '2016/2017', '1', 'GSE 101', '', 2, 20, 40, ''],                        // no such student
        [6, 'AFE/2016/0002', '2016/2017', '1', 'GSE 101', '', 2, 'abs', 40, ''],                      // not a number
        [7, 'AFE/2016/0002', '2016/2017', 'First', 'EDF111', '', '', 20, 41, ''],                     // same as row 3
    ]);
    $svc = app(LegacyImportService::class);
    $import = $svc->upload('results', $file, $this->admin);
    expect($import->columnMapping()->columns)->toMatchArray(['matric' => 1, 'session' => 2, 'semester' => 3, 'course' => 4, 'title' => 5, 'units' => 6, 'ca' => 7, 'exam' => 8, 'grade' => 9]);

    $report = $svc->check($import, $import->columnMapping(), $import->importOptions());
    expect($report->ok)->toBe(3)->and($report->problemCount)->toBe(4)->and($report->newSessions)->toBe(['2016/2017'])
        ->and(collect($report->problems)->pluck('message')->implode(' '))->toContain('PHE 102 is not in the portal')->toContain('No student has this matric')->toContain('"abs" is not a number')->toContain('appears again (first on row 4)')
        ->and(collect($report->warnings)->pluck('message')->implode(' '))->toContain('old grade C is kept');
    expect(CourseResult::count())->toBe(0)->and(AcademicSession::where('name', '2016/2017')->exists())->toBeFalse();

    // With "add courses" on, the unknown course is added (inactive) from its title and units.
    $report = $svc->check($import->fresh(), $import->columnMapping(), new \App\Data\Legacy\ImportOptions(true, true));
    expect($report->ok)->toBe(4)->and($report->newCourses)->toBe(['PHE 102 · Physical Education (1 units)']);

    $done = $svc->import($import->fresh(), $this->admin);
    expect($done->imported)->toBe(4)->and(CourseResult::where('status', 'published')->whereNotNull('legacy_import_id')->count())->toBe(4)
        ->and(Course::where('code', 'PHE 102')->value('is_active'))->toBeFalse();
    $edf = CourseResult::whereHas('course', fn ($q) => $q->where('code', 'EDF 111'))->where('student_id', $this->Ade->id)->first();
    expect($edf->grade)->toBe('C')->and($edf->points)->toEqual(3.0)->and($edf->total)->toEqual(40.0);

    // Transcript and CGPA include them: Ade has A (5 × 2) and C (3 × 3) = 19 / 5 = 3.80.
    expect(app(ResultService::class)->transcript($this->Ade)->cgpa)->toBe(3.8);
    $this->actingAs($this->Ade->user)->get(route('my.results'))->assertOk()->assertSee('GSE 101')->assertSee('2016/2017');
});

it('carries previous TCP and TNU into the CGPA, and checks they are possible', function () {
    $file = csvUpload('balances.csv', [
        ['Matric No', 'TNU', 'TCP', 'CGPA', 'TNUP', 'Session'],
        ['AFE/2016/0001', 96, 312, 3.25, 90, '2019/20'],
        ['AFE/2016/0002', 40, 250, '', '', ''],     // 250 > 40 × 5
    ]);
    $svc = app(LegacyImportService::class);
    $import = $svc->upload('balances', $file, $this->admin);
    $report = $svc->check($import, $import->columnMapping(), $import->importOptions());
    expect($report->ok)->toBe(1)->and($report->problems[0]->message)->toContain('impossible');
    $svc->import($import->fresh(), $this->admin);

    $b = LegacyBalance::where('student_id', $this->Ade->id)->first();
    expect($b->tnu)->toBe(96)->and($b->asOf->name)->toBe('2019/2020');
    $t = app(ResultService::class)->transcript($this->Ade);
    expect($t->cgpa)->toBe(3.25)->and($t->units)->toBe(96)->and($t->earned)->toBe(90)->and($t->broughtForward->tcp)->toEqual(312.0);

    // A later portal result is added on top: (312 + 5 × 2) / 98.
    CourseResult::create(['student_id' => $this->Ade->id, 'course_id' => Course::where('code', 'GSE 101')->value('id'), 'academic_session_id' => AcademicSession::where('name', '2019/2020')->value('id'), 'semester_type_id' => 1, 'total' => 75, 'grade' => 'A', 'points' => 5, 'is_pass' => true, 'credit_units' => 2, 'status' => 'published']);
    expect(app(ResultService::class)->transcript($this->Ade->fresh())->cgpa)->toBe(round(322 / 98, 2));
    $this->actingAs($this->admin)->get(route('students.transcript', $this->Ade))->assertOk()->assertSee('Brought forward');
});

it('never overwrites what the portal has, and undoes a whole import from the operation history', function () {
    $svc = app(LegacyImportService::class);
    $rows = [['Matric Number', 'Session', 'Semester', 'Course Code', 'Total'], ['AFE/2016/0001', '2017/2018', '1', 'GSE 101', 66], ['AFE/2016/0002', '2017/2018', '1', 'GSE 101', 52]];
    $first = $svc->upload('results', csvUpload('a.csv', $rows), $this->admin);
    $svc->check($first, $first->columnMapping(), $first->importOptions());
    $svc->import($first->fresh(), $this->admin);

    $again = $svc->upload('results', csvUpload('a-again.csv', $rows), $this->admin);
    $report = $svc->check($again, $again->columnMapping(), $again->importOptions());
    expect($report->ok)->toBe(0)->and($report->existing)->toBe(2);

    $op = OperationBatch::where('type', 'legacy_import')->firstOrFail();
    $this->actingAs($this->admin)->get(route('operations.reverse', $op))->assertOk()->assertSee('Undo this legacy results import');
    $handler = \App\Support\Reversals::for($op);
    $plan = $handler->plan($op);
    expect($plan->counts()['change'])->toBe(2);
    $handler->run($op, $plan, $this->admin, 'Wrong file');
    expect(CourseResult::count())->toBe(0)->and($first->fresh()->status)->toBe('undone')->and($op->fresh()->status)->toBe('reversed');
});

it('walks through upload, matching, checking and importing in the browser', function () {
    $this->actingAs($this->admin)->get(route('legacy.index'))->assertOk()->assertSee('Legacy results');
    $this->get(route('legacy.template', 'results'))->assertOk();
    $this->post(route('legacy.store'), ['kind' => 'results', 'file' => csvUpload('x.csv', [['Matric', 'Year', 'Term', 'Code', 'Score'], ['AFE/2016/0001', '2018/2019', 'Second', 'EDF 111', 58]])])->assertRedirect();
    $import = LegacyImport::latest('id')->first();
    $this->get(route('legacy.show', $import))->assertOk()->assertSee('Match the columns');

    // "Year", "Term" and "Score" are guessed; the person confirms and checks.
    $this->post(route('legacy.check', $import), ['map' => ['matric' => 0, 'session' => 1, 'semester' => 2, 'course' => 3, 'total' => 4], 'options' => ['create_sessions' => 1]])->assertSessionHas('success');
    $this->get(route('legacy.show', $import))->assertSee('ready to import')->assertSee('2018/2019');
    $this->post(route('legacy.import', $import))->assertSessionHas('success');
    expect(CourseResult::where('legacy_import_id', $import->id)->count())->toBe(1);
    $this->get(route('legacy.problems', $import))->assertOk();

    $student = User::where('email', 'bola@example.com')->first();
    $this->actingAs($student)->get(route('legacy.index'))->assertForbidden();
});

it('reads session and semester spellings from old systems', function () {
    expect(LegacyImportService::sessionName('2018/19'))->toBe('2018/2019')
        ->and(LegacyImportService::sessionName('2018 - 2019'))->toBe('2018/2019')
        ->and(LegacyImportService::sessionName('2018/2020'))->toBeNull()
        ->and(LegacyImportService::sessionName('2018'))->toBeNull()
        ->and(LegacyImportService::tidyCode('csc101'))->toBe('CSC 101');
});

it('reads an Excel export as well as CSV', function () {
    if (! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $this->markTestSkipped('phpoffice/phpspreadsheet is not installed (Excel files are optional; CSV always works).');
    }
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getActiveSheet()->fromArray([['Reg No', 'Session', 'Semester', 'Course', 'Total'], ['AFE/2016/0002', '2017/2018', 'Rain', 'EDF 111', 71.5]]);
    $path = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
    $svc = app(LegacyImportService::class);
    $import = $svc->upload('results', new UploadedFile($path, 'old-results.xlsx', null, null, true), $this->admin);
    $report = $svc->check($import, $import->columnMapping(), $import->importOptions());
    expect($report->ok)->toBe(1)->and($report->preview[0]['grade'])->toBe('A')->and($report->preview[0]['semester'])->toBe('Second Semester');
});
