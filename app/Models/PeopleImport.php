<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A spreadsheet of students or staff being added to the portal: uploaded, checked, imported, perhaps undone. */
class PeopleImport extends Model
{
    protected $fillable = ['kind', 'file_name', 'path', 'rows', 'status', 'report', 'created_ids', 'imported', 'created_by', 'imported_at', 'undone_at'];

    protected $casts = ['report' => 'array', 'created_ids' => 'array', 'imported_at' => 'datetime', 'undone_at' => 'datetime'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
