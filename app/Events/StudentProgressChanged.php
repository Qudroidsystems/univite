<?php

namespace App\Events;

use App\Models\Student;
use Illuminate\Foundation\Events\Dispatchable;

/** Something clearance depends on changed for this student (an offer accepted, a fee paid, a document uploaded ...). */
class StudentProgressChanged
{
    use Dispatchable;

    public function __construct(public readonly Student $student)
    {
    }
}
