<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class CustomField extends Model
{
    public const TARGETS = ['applicant' => 'Applicants', 'student' => 'Students', 'staff' => 'Staff'];
    public const TYPES = [
        'text' => 'Short text', 'textarea' => 'Long text (paragraph)', 'number' => 'Number', 'date' => 'Date',
        'select' => 'Choose from a list', 'checkbox' => 'Yes / No', 'file' => 'File upload',
    ];
    /** The record class each target's answers belong to. */
    public const SUBJECTS = ['applicant' => Applicant::class, 'student' => Student::class, 'staff' => User::class];

    protected $fillable = ['target', 'key', 'label', 'type', 'options', 'section', 'help_text', 'is_required', 'self_service', 'accept', 'max_kb', 'sort_order', 'is_active'];

    protected $casts = ['options' => 'array', 'is_required' => 'boolean', 'self_service' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        // Deleting a question also removes the files people uploaded for it.
        static::deleting(function (CustomField $field) {
            $field->values()->whereNotNull('file_path')->pluck('file_path')->each(fn ($p) => Storage::disk('local')->delete($p));
        });
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** The form input name for this field. */
    public function inputName(): string
    {
        return 'cf_' . $this->key;
    }
}
