<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('opens every campus services page for the administrator, and the personal pages for a student and staff member', function () {
    campusWorld($this);
    $student = makeStudent($this, 'Ada');
    $staff = makeStaff($this, 'Grace Lecturer');

    foreach (['polls.index', 'polls.create', 'id-cards.index', 'id-cards.settings', 'library.items.index', 'library.items.create', 'library.import', 'library.desk',
        'library.loans', 'library.fines', 'library.settings', 'clinic.index', 'clinic.stats', 'hostels.index', 'hostels.create', 'accommodation.allocations',
        'accommodation.settings', 'tp.exercises.index', 'tp.exercises.create', 'tp.schools.index', 'dashboard'] as $route) {
        $this->actingAs($this->admin)->get(route($route))->assertOk();
    }
    $this->get(route('clinic.patient', $student->user))->assertOk();
    $this->get(route('id-cards.print'))->assertOk();

    foreach (['my.polls', 'my.id-card', 'my.library', 'my.health', 'my.accommodation', 'my.tp'] as $route) {
        $this->actingAs($student->user)->get(route($route))->assertOk();
    }
    foreach (['my.polls', 'my.id-card', 'my.library', 'my.health'] as $route) {
        $this->actingAs($staff)->get(route($route))->assertOk();
    }
    $this->actingAs($staff)->get(route('my.accommodation'))->assertForbidden();
});
