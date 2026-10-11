<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Lookups cached in static properties outlive a test's database, so every test starts with them empty. */
    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\GradeScale::flush();
        \App\Support\Institution::flush();
        \App\Support\Lookups::flush();
        \App\Models\Holiday::flush();
        \App\Models\StudentStatus::flush();
    }
}
