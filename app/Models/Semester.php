<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Semester extends Model
{
    protected $fillable = ['academic_session_id', 'name', 'sort_order', 'start_date', 'end_date', 'registration_opens_at', 'registration_closes_at', 'is_current'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'registration_opens_at'  => 'datetime',
        'registration_closes_at' => 'datetime',
        'is_current' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public static function current(): ?self
    {
        return static::with('session')->where('is_current', true)->first();
    }

    /** Make this the current semester; its session becomes the current session too. */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            $this->session->makeCurrent();
            static::where('academic_session_id', $this->academic_session_id)->update(['is_current' => false]);
            $this->update(['is_current' => true]);
        });
    }
}
