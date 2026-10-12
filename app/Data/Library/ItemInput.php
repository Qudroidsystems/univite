<?php

namespace App\Data\Library;

use App\Models\LibraryItem;

/** The bibliographic details of a catalogue title, as typed on its form or read from an import file. */
final readonly class ItemInput
{
    public function __construct(
        public string $title,
        public string $kind = 'book',
        public ?string $authors = null,
        public ?string $isbn = null,
        public ?string $publisher = null,
        public ?int $year = null,
        public ?string $edition = null,
        public ?string $callNumber = null,
        public ?string $subject = null,
        public ?string $description = null,
        public ?string $url = null,
        public bool $isReference = false,
    ) {
    }

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'], 'kind' => ['required', 'in:' . implode(',', array_keys(LibraryItem::KINDS))],
            'authors' => ['nullable', 'string', 'max:255'], 'isbn' => ['nullable', 'string', 'max:20'], 'publisher' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1000', 'max:2100'], 'edition' => ['nullable', 'string', 'max:50'], 'call_number' => ['nullable', 'string', 'max:50'],
            'subject' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'url' => ['nullable', 'url', 'max:255'],
            'is_reference' => ['nullable', 'boolean'],
        ];
    }

    public static function fromArray(array $d): self
    {
        $s = fn ($k) => isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;

        return new self(
            trim((string) $d['title']), $s('kind') ?? 'book', $s('authors'), $s('isbn') ? str_replace(['-', ' '], '', $s('isbn')) : null, $s('publisher'),
            $s('year') ? (int) $s('year') : null, $s('edition'), $s('call_number'), $s('subject'), $s('description'), $s('url'), (bool) ($d['is_reference'] ?? false),
        );
    }

    public function attributes(): array
    {
        return ['title' => $this->title, 'kind' => $this->kind, 'authors' => $this->authors, 'isbn' => $this->isbn, 'publisher' => $this->publisher,
            'year' => $this->year, 'edition' => $this->edition, 'call_number' => $this->callNumber, 'subject' => $this->subject,
            'description' => $this->description, 'url' => $this->url, 'is_reference' => $this->isReference];
    }
}
