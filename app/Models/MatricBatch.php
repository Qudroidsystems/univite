<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of matric numbers waiting for approval (or approved / rejected). */
class MatricBatch extends Model
{
    public const STATUSES = ['pending' => 'Awaiting approval', 'approved' => 'Approved and published', 'rejected' => 'Rejected'];
    public const BADGES = ['pending' => 'bg-warning-subtle text-warning', 'approved' => 'bg-success-subtle text-success', 'rejected' => 'bg-secondary-subtle text-secondary'];

    protected $fillable = ['code', 'source', 'status', 'created_by', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['decided_at' => 'datetime'];

    /** A new pending batch with an unused code. "auto" batches collect numbers reserved on clearance or admission. */
    public static function open(?User $by, string $source = 'generated'): self
    {
        do {
            $code = ($source === 'auto' ? 'A' : '') . strtoupper(\Illuminate\Support\Str::random($source === 'auto' ? 7 : 8));
        } while (self::where('code', $code)->exists() || MatricEvent::where('batch', $code)->exists());

        return self::create(['code' => $code, 'source' => $source, 'status' => 'pending', 'created_by' => $by?->id]);
    }

    /** Numbers reserved automatically collect in one open batch until it is decided. */
    public static function openAuto(?User $by = null): self
    {
        return self::where('source', 'auto')->where('status', 'pending')->latest('id')->first() ?? self::open($by, 'auto');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'provisional_batch', 'code');
    }

    /** Every number proposed in this batch, including ones since published or released. */
    public function proposals(): HasMany
    {
        return $this->hasMany(MatricEvent::class, 'batch', 'code')->where('action', 'proposed');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
