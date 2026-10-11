<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lecture or examination timetable for one semester. It is prepared by the committee (draft), sent for review,
 * approved, then published. A published timetable is never edited: a revision is a new version that replaces it when
 * that version is published.
 */
class Timetable extends Model
{
    public const KINDS = ['lecture' => 'Lecture timetable', 'exam' => 'Examination timetable'];
    public const STATUSES = ['draft' => 'Being prepared', 'review' => 'Sent for approval', 'approved' => 'Approved', 'published' => 'Published', 'superseded' => 'Replaced by a newer version'];
    public const BADGES = ['draft' => 'bg-secondary-subtle text-secondary', 'review' => 'bg-warning-subtle text-warning', 'approved' => 'bg-info-subtle text-info', 'published' => 'bg-success-subtle text-success', 'superseded' => 'bg-light text-muted'];
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** Soft rules the committee can weigh (0 = ignore, 5 = very important). key => [label, applies to lecture|exam|both, default]. */
    public const PREFERENCES = [
        'early_late'  => ['Avoid very early and very late periods', 'both', 3],
        'gaps'        => ['Keep a class\'s day compact (few idle gaps)', 'lecture', 2],
        'spread'      => ['Spread a student\'s papers / a course\'s meetings across days', 'both', 4],
        'balance'     => ['Use the smallest suitable venue and balance use across venues', 'both', 2],
        'preferences' => ['Respect preferred venues and the owning department\'s rooms', 'both', 2],
        'consecutive' => ['Avoid long runs of back-to-back periods', 'lecture', 2],
        'room_changes'=> ['Avoid moving a class between buildings for back-to-back periods', 'lecture', 1],
        'large_first' => ['Hold the largest papers early in the examination period', 'exam', 1],
    ];

    protected $fillable = [
        'kind', 'name', 'academic_session_id', 'semester_type_id', 'version', 'previous_id', 'status', 'config', 'committee', 'summary',
        'generated_at', 'generated_by', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by', 'decision_note', 'published_at', 'published_by', 'created_by',
    ];

    protected $casts = [
        'config' => 'array', 'committee' => 'array', 'summary' => 'array',
        'generated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'draft', 'version' => 1];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(Timetable::class, 'previous_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TimetableItem::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function invigilators(): HasMany
    {
        return $this->hasMany(TimetableInvigilator::class);
    }

    public function seatingPlans(): HasMany
    {
        return $this->hasMany(SeatingPlan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isExam(): bool
    {
        return $this->kind === 'exam';
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function weight(string $key): int
    {
        return (int) ($this->config['weights'][$key] ?? self::PREFERENCES[$key][2] ?? 0);
    }

    public function getTitleAttribute(): string
    {
        return $this->name . ($this->version > 1 ? ' (version ' . $this->version . ')' : '');
    }

    /** The published timetable of a kind for a semester, if any. */
    public static function published(string $kind, int $sessionId, int $semesterTypeId): ?self
    {
        return static::where(['kind' => $kind, 'academic_session_id' => $sessionId, 'semester_type_id' => $semesterTypeId, 'status' => 'published'])->latest('version')->first();
    }

    /** Can this user prepare (edit) it? Committee members, or anyone allowed to approve, when a committee is named. */
    public function preparedBy(User $user): bool
    {
        if (! $user->can('Create timetables')) {
            return false;
        }
        $committee = $this->committee ?? [];

        return ! $committee || in_array($user->id, $committee, true) || $user->can('Approve timetables');
    }
}
