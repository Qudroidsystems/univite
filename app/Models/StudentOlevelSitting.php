<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One O Level sitting a student submitted: exam, year and number, the scanned result, the grades they typed, the grades
 * read from the scan (by the AI service, when it is on) and the grades the school confirmed.
 */
class StudentOlevelSitting extends Model
{
    public const EXAMS = ['waec' => 'WAEC (SSCE)', 'neco' => 'NECO (SSCE)', 'nabteb' => 'NABTEB', 'gce' => 'WAEC GCE', 'neco_gce' => 'NECO GCE'];

    /** Grades best first. */
    public const GRADES = ['A1', 'B2', 'B3', 'C4', 'C5', 'C6', 'D7', 'E8', 'F9'];

    public const CREDIT_GRADES = ['A1', 'B2', 'B3', 'C4', 'C5', 'C6'];

    public const STATUSES = ['draft' => 'Not submitted', 'submitted' => 'Awaiting confirmation', 'confirmed' => 'Confirmed', 'rejected' => 'Returned'];

    public const BADGES = ['draft' => 'bg-secondary-subtle text-secondary', 'submitted' => 'bg-warning-subtle text-warning', 'confirmed' => 'bg-success-subtle text-success', 'rejected' => 'bg-danger-subtle text-danger'];

    protected $fillable = [
        'student_id', 'exam_type', 'exam_year', 'exam_number', 'centre', 'document_path', 'document_name', 'document_mime', 'status',
        'extraction_status', 'extracted', 'extraction_note', 'extracted_at', 'submitted_at', 'reviewed_by', 'reviewed_at', 'grade_source', 'review_note',
    ];

    protected $casts = ['extracted' => 'array', 'extracted_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'exam_year' => 'integer'];

    protected $attributes = ['status' => 'draft', 'extraction_status' => 'none'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(StudentOlevelResult::class)->orderBy('subject');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function label(): string
    {
        return (self::EXAMS[$this->exam_type] ?? strtoupper($this->exam_type)) . " {$this->exam_year} · {$this->exam_number}";
    }

    /** Position of a grade on the scale: 0 = A1 (best) ... 8 = F9; 99 = unknown. */
    public static function rank(?string $grade): int
    {
        $i = array_search(strtoupper((string) $grade), self::GRADES, true);

        return $i === false ? 99 : $i;
    }

    public static function normaliseGrade(?string $grade): ?string
    {
        $g = strtoupper(preg_replace('/\s+/', '', (string) $grade));
        if (in_array($g, self::GRADES, true)) {
            return $g;
        }
        // "A" -> A1, "C 6" -> C6, "B-2" -> B2
        $g = preg_replace('/[^A-F0-9]/', '', $g);
        if (in_array($g, self::GRADES, true)) {
            return $g;
        }
        $byLetter = ['A' => 'A1', 'D' => 'D7', 'E' => 'E8', 'F' => 'F9'];

        return $byLetter[$g] ?? null;
    }
}
