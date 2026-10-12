<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A title in the library catalogue. */
class LibraryItem extends Model
{
    public const KINDS = ['book' => 'Book', 'journal' => 'Journal or serial', 'thesis' => 'Thesis or project', 'reference' => 'Reference work', 'ebook' => 'Electronic resource', 'other' => 'Other'];

    protected $fillable = ['kind', 'title', 'authors', 'isbn', 'publisher', 'year', 'edition', 'call_number', 'subject', 'description', 'url', 'is_reference', 'created_by'];

    protected $casts = ['is_reference' => 'boolean', 'year' => 'integer'];

    public function copies(): HasMany
    {
        return $this->hasMany(LibraryCopy::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(LibraryHold::class);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(fn ($w) => $w->where('title', 'like', "%{$term}%")->orWhere('authors', 'like', "%{$term}%")
            ->orWhere('isbn', 'like', "%{$term}%")->orWhere('call_number', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%")
            ->orWhereHas('copies', fn ($c) => $c->where('accession_number', $term)));
    }

    public function citation(): string
    {
        return trim($this->title . ($this->authors ? ' / ' . $this->authors : '') . ($this->edition ? ', ' . $this->edition : '') . ($this->year ? ' (' . $this->year . ')' : ''));
    }
}
