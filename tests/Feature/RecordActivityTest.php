<?php

use App\Models\Course;
use App\Models\RecordActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => campusWorld($this));

it('records who creates, changes and deletes a record, only when someone is signed in', function () {
    Course::create(['department_id' => $this->dept->id, 'code' => 'SYS101', 'title' => 'Seeded', 'credit_units' => 2, 'level' => 100]);
    expect(RecordActivity::count())->toBe(0);

    $staff = makeStaff($this, 'Grace Okon', null, ['View courses', 'Create courses', 'Edit courses', 'Delete courses']);
    $this->actingAs($staff);
    $course = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU201', 'title' => 'Curriculum Studies', 'credit_units' => 2, 'level' => 200]);
    $course->update(['title' => 'Curriculum Studies I']);
    $course->touch();                                   // only updated_at: no history line
    $course->delete();

    $log = RecordActivity::where('subject_type', $course->getMorphClass())->where('subject_id', $course->id)->orderBy('id')->get();
    expect($log->pluck('action')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($log[1]->fields)->toBe(['title'])
        ->and($log->pluck('user_id')->unique()->all())->toBe([$staff->id])
        ->and($log[2]->label)->toBe('EDU201 Curriculum Studies I');
});

it('shows the course ID and the last person to change each course, with their details on request', function () {
    $staff = makeStaff($this, 'Grace Okon', null, ['View courses', 'Edit courses']);
    $this->actingAs($staff);
    $course = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU201', 'title' => 'Curriculum Studies', 'credit_units' => 2, 'level' => 200]);

    $page = $this->actingAs($staff)->get(route('academics.courses.index'))->assertOk();
    $page->assertSee('Last changed by')->assertSee('#' . $course->id)->assertSee($staff->staffProfile->staff_number)
        ->assertSee(route('activity.show', ['Course', $course->id]), false);

    $this->get(route('activity.show', ['Course', $course->id]))->assertOk()
        ->assertSee('Grace Okon')->assertSee($staff->staffProfile->staff_number)->assertSee('Created');
    $this->get('/activity/Nope/1')->assertNotFound();

    $student = makeStudent($this, 'Ada');
    $this->actingAs($student->user)->get(route('activity.show', ['Course', $course->id]))->assertForbidden();
});
