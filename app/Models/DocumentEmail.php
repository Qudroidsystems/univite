<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One emailed document and whether it went out. */
class DocumentEmail extends Model
{
    protected $fillable = ['type', 'subject_type', 'subject_id', 'recipient', 'subject', 'status', 'error', 'sent_by'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
