<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bill extends Model
{
    public const STUDENT_TYPES = ['all' => 'All students', 'fresh' => 'Fresh students', 'returning' => 'Returning students'];

    protected $fillable = ['academic_session_id', 'programme_id', 'level', 'bill_item_id', 'student_type', 'amount', 'is_active'];

    protected $casts = ['amount' => 'decimal:2', 'is_active' => 'boolean'];

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
