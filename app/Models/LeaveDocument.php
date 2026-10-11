<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supporting document. Confidential (medical) ones open only for the owner and staff allowed to see them. */
class LeaveDocument extends Model
{
    protected $fillable = ['leave_request_id', 'path', 'original_name', 'mime', 'size', 'confidential', 'uploaded_by'];

    protected $casts = ['confidential' => 'boolean'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
