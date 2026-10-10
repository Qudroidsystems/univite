<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One scope's overrides of the academic rules (see App\Support\AcademicRules). */
class AcademicRule extends Model
{
    protected $fillable = ['scope_type', 'scope_id', 'level', 'semester_type_id', 'rules', 'updated_by'];

    protected $casts = ['rules' => 'array', 'level' => 'integer', 'semester_type_id' => 'integer', 'scope_id' => 'integer'];

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
