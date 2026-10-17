<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of the key to result: a GPA range, the remark printed for it, the student's standing and what happens next. */
class ResultKey extends Model
{
    use Concerns\HasKeyScope;

    public const ACTIONS = [
        'proceed' => 'Proceed to the next level',
        'carry_over' => 'Proceed, carrying failed courses',
        'probation' => 'Probation',
        'repeat' => 'Repeat the level',
        'withdraw' => 'Advised to withdraw',
        'graduate' => 'Eligible to graduate',
    ];

    protected $fillable = ['faculty_id', 'department_id', 'study_category_id', 'code', 'min_gpa', 'max_gpa', 'rank', 'action', 'activity'];

    protected $casts = ['faculty_id' => 'integer', 'department_id' => 'integer', 'study_category_id' => 'integer', 'min_gpa' => 'float', 'max_gpa' => 'float'];

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }
}
