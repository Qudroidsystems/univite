<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One physical copy, identified by its accession number. */
class LibraryCopy extends Model
{
    public const STATUSES = ['available' => 'On the shelf', 'on_loan' => 'On loan', 'on_hold' => 'Kept for a borrower', 'repair' => 'Being repaired', 'lost' => 'Lost', 'withdrawn' => 'Withdrawn'];

    public const BADGES = ['available' => 'bg-success-subtle text-success', 'on_loan' => 'bg-info-subtle text-info', 'on_hold' => 'bg-warning-subtle text-warning',
        'repair' => 'bg-secondary-subtle text-secondary', 'lost' => 'bg-danger-subtle text-danger', 'withdrawn' => 'bg-dark-subtle text-dark'];

    protected $fillable = ['library_item_id', 'accession_number', 'location', 'status', 'acquired_on', 'price', 'note'];

    protected $casts = ['acquired_on' => 'date', 'price' => 'decimal:2'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'library_item_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class);
    }

    public function openLoan(): HasOne
    {
        return $this->hasOne(LibraryLoan::class)->whereNull('returned_at');
    }
}
