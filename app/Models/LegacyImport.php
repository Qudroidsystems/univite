<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One uploaded spreadsheet of old results or previous totals, from column matching to import (and undo). */
class LegacyImport extends Model
{
    public const KINDS = ['results' => 'Course results', 'balances' => 'Previous totals (TCP / TNU)'];
    public const STATUSES = ['draft' => 'Columns to match', 'checked' => 'Checked, ready to import', 'imported' => 'Imported', 'undone' => 'Undone'];
    public const BADGES = ['draft' => 'bg-secondary-subtle text-secondary', 'checked' => 'bg-info-subtle text-info', 'imported' => 'bg-success-subtle text-success', 'undone' => 'bg-warning-subtle text-warning'];

    protected $fillable = ['kind', 'file_name', 'path', 'headers', 'mapping', 'options', 'report', 'status', 'rows', 'imported', 'operation_batch_id', 'created_by', 'imported_at'];

    protected $casts = ['headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'report' => 'array', 'imported_at' => 'datetime'];

    public function columnMapping(): \App\Data\Legacy\ColumnMapping
    {
        return \App\Data\Legacy\ColumnMapping::fromArray($this->mapping, $this->headers ?? []);
    }

    public function importOptions(): \App\Data\Legacy\ImportOptions
    {
        return \App\Data\Legacy\ImportOptions::fromArray($this->options);
    }

    public function reportData(): ?\App\Data\Legacy\ImportReport
    {
        return \App\Data\Legacy\ImportReport::fromArray($this->report);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(OperationBatch::class, 'operation_batch_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(CourseResult::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(LegacyBalance::class);
    }
}
