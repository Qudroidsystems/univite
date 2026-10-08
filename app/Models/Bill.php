<?php

namespace App\Models;

use App\Support\Levels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bill extends Model
{
    public const STUDENT_TYPES = ['all' => 'All students', 'fresh' => 'Fresh students', 'returning' => 'Returning students'];

    /** Who a charge is for by nationality. "home" is the university's own country (setting institution.home_nationality). */
    public const NATIONALITIES = ['any' => 'Everyone', 'home' => 'Home-country students only', 'foreign' => 'Foreign students only'];

    protected $fillable = [
        'academic_session_id', 'faculty_id', 'department_id', 'study_category_id', 'programme_id', 'level', 'semester_type_id',
        'bill_item_id', 'student_type', 'nationality', 'amount', 'installments', 'installment_percents', 'opens_at', 'closes_at', 'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2', 'is_active' => 'boolean', 'installment_percents' => 'array',
        'opens_at' => 'datetime', 'closes_at' => 'datetime',
    ];

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    /** How many targeting fields are filled in: a more specific bill beats a general one for the same item. */
    public function specificity(): int
    {
        return collect(['faculty_id', 'department_id', 'study_category_id', 'programme_id', 'level', 'semester_type_id'])
            ->filter(fn ($f) => $this->{$f} !== null)->count()
            + ($this->nationality !== 'any' ? 1 : 0) + ($this->student_type !== 'all' ? 1 : 0);
    }

    /** Short text describing who this bill is for, e.g. "Faculty of Education · NCE 2 · Foreign". */
    public function targetLabel(): string
    {
        $parts = array_filter([
            $this->programme?->name ?? $this->department?->name ?? $this->faculty?->name ?? $this->studyCategory?->name,
            $this->level ? Levels::label((int) $this->level, $this->programme?->studyCategory ?? $this->studyCategory) : null,
            $this->semesterType?->name,
            $this->student_type !== 'all' ? self::STUDENT_TYPES[$this->student_type] ?? null : null,
            $this->nationality !== 'any' ? self::NATIONALITIES[$this->nationality] : null,
        ]);

        return $parts ? implode(' · ', $parts) : 'Everyone';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillItem::class, 'bill_item_id');
    }
}
