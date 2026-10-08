<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CustomFieldValue extends Model
{
    protected $fillable = ['custom_field_id', 'subject_type', 'subject_id', 'value', 'file_path', 'original_name', 'size'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
