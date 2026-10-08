<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicantResult extends Model
{
    public const EXAMS = ['waec' => 'WAEC (SSCE)', 'neco' => 'NECO (SSCE)', 'nabteb' => 'NABTEB (SSCE)', 'nce' => 'NCE', 'nd' => 'ND'];

    /** Grades accepted per exam type, best first. */
    public const GRADES = [
        'ssce' => ['A1', 'B2', 'B3', 'C4', 'C5', 'C6', 'D7', 'E8', 'F9'],
        'nce'  => ['Distinction', 'Credit', 'Merit', 'Pass'],
        'nd'   => ['Distinction', 'Upper Credit', 'Lower Credit', 'Pass'],
    ];

    public const CREDIT_GRADES = ['A1', 'B2', 'B3', 'C4', 'C5', 'C6'];

    protected $fillable = ['applicant_id', 'exam_type', 'exam_year', 'exam_number', 'subject', 'grade'];

    public static function gradesFor(string $examType): array
    {
        return self::GRADES[in_array($examType, ['nce', 'nd']) ? $examType : 'ssce'];
    }

    public function isSsce(): bool
    {
        return in_array($this->exam_type, ['waec', 'neco', 'nabteb'], true);
    }
}
