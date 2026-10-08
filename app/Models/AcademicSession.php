<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class AcademicSession extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'is_current'];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_current' => 'boolean',
    ];

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class)->orderBy('sort_order');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public static function current(): ?self
    {
        return static::where('is_current', true)->first();
    }

    /** Make this the one current session (and clear the current semester if it belongs elsewhere). */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            static::where('id', '!=', $this->id)->update(['is_current' => false]);
            $this->update(['is_current' => true]);
            Semester::where('academic_session_id', '!=', $this->id)->update(['is_current' => false]);
        });
    }
}
