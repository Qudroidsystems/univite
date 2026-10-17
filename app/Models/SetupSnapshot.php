<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved copy of part of the setup that can be restored; see App\Services\Setup\SetupSnapshots. */
class SetupSnapshot extends Model
{
    protected $fillable = ['label', 'kind', 'academic_session_id', 'sections', 'payload', 'summary', 'note', 'created_by'];

    protected $casts = ['sections' => 'array', 'summary' => 'array'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> section key => captured data */
    public function data(): array
    {
        return json_decode($this->payload, true) ?: [];
    }
}
