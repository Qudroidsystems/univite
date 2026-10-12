<?php

namespace App\Services\Library;

use App\Data\Campus\ChargeLine;
use App\Data\Library\BorrowerStanding;
use App\Data\Library\ImportResult;
use App\Data\Library\ItemInput;
use App\Data\Library\ReturnResult;
use App\Models\LibraryCopy;
use App\Models\LibraryHold;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\StudentStatus;
use App\Models\User;
use App\Services\Campus\StudentCharges;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Lending: issuing, returning and renewing copies, holds, overdue fines, lost books and reminders. */
class LibraryService
{
    public function __construct(
        private LibrarySettings $settings,
        private StudentCharges $charges,
        private Messenger $messenger,
        private OperationAudit $audit,
    ) {
    }

    // ---------------------------------------------------------------- catalogue

    public function createItem(ItemInput $in, User $by, int $copies = 0, ?string $location = null, ?float $price = null): LibraryItem
    {
        return DB::transaction(function () use ($in, $by, $copies, $location, $price) {
            $item = LibraryItem::create($in->attributes() + ['created_by' => $by->id]);
            if ($copies > 0 && $in->kind !== 'ebook') {
                $this->addCopies($item, $copies, [], $location, $price);
            }

            return $item;
        });
    }

    public function updateItem(LibraryItem $item, ItemInput $in): LibraryItem
    {
        $item->update($in->attributes());

        return $item;
    }

    /**
     * Add copies to a title, numbered automatically or with the accession numbers given.
     *
     * @param  string[]  $accessions
     */
    public function addCopies(LibraryItem $item, int $count, array $accessions = [], ?string $location = null, ?float $price = null): int
    {
        $accessions = array_values(array_unique(array_filter(array_map('trim', $accessions))));
        if ($taken = LibraryCopy::whereIn('accession_number', $accessions)->pluck('accession_number')->all()) {
            throw new RuntimeException('These accession numbers are already used: ' . implode(', ', $taken) . '.');
        }
        $numbers = $accessions ?: $this->nextAccessions(max(0, min(500, $count)));
        foreach ($numbers as $n) {
            $item->copies()->create(['accession_number' => $n, 'location' => $location, 'price' => $price, 'acquired_on' => now()->toDateString(), 'status' => 'available']);
        }

        return count($numbers);
    }

    /** @return string[] */
    private function nextAccessions(int $count): array
    {
        $prefix = strtoupper(trim((string) $this->settings->get('accession_prefix')));
        $n = (int) LibraryCopy::max('id');
        $out = [];
        while (count($out) < $count) {
            $candidate = sprintf('%s%06d', $prefix, ++$n);
            if (! LibraryCopy::where('accession_number', $candidate)->exists()) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * Rows from a spreadsheet: Title, Authors, ISBN, Publisher, Year, Call number, Subject, Copies (headings in the first row,
     * in any order). Titles already in the catalogue with the same ISBN get the new copies instead of a second entry.
     *
     * @param  array<int, array<int, mixed>>  $table
     */
    public function import(array $table, User $by, ?string $location = null): ImportResult
    {
        $head = array_map(fn ($h) => strtolower(trim(str_replace(['_', '.'], ' ', (string) $h))), array_shift($table) ?? []);
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $map = ['title' => $col(['title']), 'authors' => $col(['authors', 'author']), 'isbn' => $col(['isbn']), 'publisher' => $col(['publisher']),
            'year' => $col(['year', 'year published']), 'call_number' => $col(['call number', 'call no', 'classification']), 'subject' => $col(['subject']),
            'edition' => $col(['edition']), 'copies' => $col(['copies', 'number of copies', 'quantity'])];
        if ($map['title'] === null) {
            throw new RuntimeException('The first row must have the column headings, including Title.');
        }
        $titles = $copies = 0;
        $errors = [];
        $op = $this->audit->begin('library_import', 'library', $by, null, null, ['rows' => count($table)]);
        foreach ($table as $i => $row) {
            $v = fn ($k) => $map[$k] === null ? null : (trim((string) ($row[$map[$k]] ?? '')) ?: null);
            if (! $v('title')) {
                $errors[] = 'Row ' . ($i + 2) . ': no title.';
                continue;
            }
            $year = $v('year');
            if ($year !== null && (! ctype_digit($year) || (int) $year < 1000 || (int) $year > 2100)) {
                $errors[] = 'Row ' . ($i + 2) . ': "' . $year . '" is not a year.';
                $year = null;
            }
            $in = ItemInput::fromArray(['title' => $v('title'), 'authors' => $v('authors'), 'isbn' => $v('isbn'), 'publisher' => $v('publisher'), 'year' => $year,
                'call_number' => $v('call_number'), 'subject' => $v('subject'), 'edition' => $v('edition')]);
            $n = $v('copies') !== null ? max(0, (int) $v('copies')) : 1;
            $item = $in->isbn ? LibraryItem::where('isbn', $in->isbn)->first() : null;
            if (! $item) {
                $item = LibraryItem::create($in->attributes() + ['created_by' => $by->id]);
                $titles++;
            }
            $copies += $this->addCopies($item, $n, [], $location);
        }
        $this->audit->finish($op, $titles, count($errors), 0);

        return new ImportResult($titles, $copies, $errors);
    }

    // ---------------------------------------------------------------- borrowers

    public function limit(User $user): int
    {
        return $user->student ? $this->settings->int('student_limit') : $this->settings->int('staff_limit');
    }

    public function loanDays(User $user): int
    {
        return max(1, $user->student ? $this->settings->int('student_days') : $this->settings->int('staff_days'));
    }

    public function standing(User $user): BorrowerStanding
    {
        $open = LibraryLoan::where('user_id', $user->id)->whereNull('returned_at')->get();
        $owed = $this->finesOwed($user);
        $limit = $this->limit($user);
        $why = match (true) {
            ! $user->student && ! $user->staffProfile => 'only students and staff can borrow',
            (bool) $user->student && ! in_array($user->student->status, StudentStatus::codesWhere('is_enrolled'), true) => 'not an enrolled student',
            $user->staffProfile?->exit_date !== null && ! $user->student => 'has left the institution',
            $open->count() >= $limit => 'already has ' . $open->count() . ' book(s) out, the most allowed',
            $open->contains(fn ($l) => $l->isOverdue()) => 'has an overdue book that must be returned first',
            $this->settings->on('block_on_fines') && $owed > 0 => 'owes ₦' . number_format($owed, 2) . ' in library fines',
            default => null,
        };

        return new BorrowerStanding($open->count(), $limit, $open->filter(fn ($l) => $l->isOverdue())->count(), $owed, $why);
    }

    public function finesOwed(User $user): float
    {
        return (float) LibraryLoan::with('invoice')->where('user_id', $user->id)->whereIn('fine_status', ['owed', 'invoiced'])->get()
            ->filter(fn ($l) => $l->fineUnpaid())->sum(fn ($l) => $l->fine_status === 'invoiced' ? max(0, (float) $l->invoice->total - (float) $l->invoice->amount_paid) : (float) $l->fine);
    }

    // ---------------------------------------------------------------- lending

    public function findCopy(string $accession): ?LibraryCopy
    {
        return LibraryCopy::with('item')->where('accession_number', trim($accession))->first();
    }

    public function issue(LibraryCopy $copy, User $borrower, User $by): LibraryLoan
    {
        if ($copy->item->is_reference) {
            throw new RuntimeException('"' . $copy->item->title . '" is for use in the library only.');
        }
        $hold = null;
        if ($copy->status === 'on_hold') {
            $hold = LibraryHold::where('library_copy_id', $copy->id)->where('status', 'ready')->first();
            if ($hold && $hold->user_id !== $borrower->id) {
                throw new RuntimeException('This copy is being kept for ' . $hold->user->name . ' until ' . $hold->expires_at->format('j M') . '.');
            }
        } elseif ($copy->status !== 'available') {
            throw new RuntimeException('Copy ' . $copy->accession_number . ' is ' . strtolower(LibraryCopy::STATUSES[$copy->status]) . ', not on the shelf.');
        }
        $standing = $this->standing($borrower);
        if (! $standing->canBorrow()) {
            throw new RuntimeException($borrower->name . ' cannot borrow: ' . $standing->blockedBecause . '.');
        }

        return DB::transaction(function () use ($copy, $borrower, $by, $hold) {
            $locked = LibraryCopy::whereKey($copy->id)->lockForUpdate()->first();
            if (! in_array($locked->status, ['available', 'on_hold'], true)) {
                throw new RuntimeException('Copy ' . $copy->accession_number . ' was just issued to someone else.');
            }
            $loan = LibraryLoan::create(['library_copy_id' => $copy->id, 'user_id' => $borrower->id, 'issued_at' => now(),
                'due_at' => now()->addDays($this->loanDays($borrower))->endOfDay(), 'issued_by' => $by->id]);
            $locked->update(['status' => 'on_loan']);
            $hold?->update(['status' => 'fulfilled']);
            LibraryHold::where('library_item_id', $copy->library_item_id)->where('user_id', $borrower->id)->where('status', 'waiting')->update(['status' => 'fulfilled']);

            return $loan;
        });
    }

    /** @return array{0: int, 1: float} days late (after any grace) and the fine */
    public function fineFor(LibraryLoan $loan, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $end = $loan->returned_at ? CarbonImmutable::parse($loan->returned_at) : $at;
        $late = (int) CarbonImmutable::parse($loan->due_at)->startOfDay()->diffInDays($end->startOfDay(), false);
        $days = max(0, $late - $this->settings->int('grace_days'));
        $fine = $days * $this->settings->float('fine_per_day');
        $cap = $this->settings->float('max_fine');

        return [$days, round($cap > 0 ? min($fine, $cap) : $fine, 2)];
    }

    public function giveBack(LibraryCopy $copy, User $by): ReturnResult
    {
        $loan = $copy->openLoan;
        if (! $loan) {
            throw new RuntimeException('Copy ' . $copy->accession_number . ' is not on loan.');
        }

        return DB::transaction(function () use ($copy, $loan, $by) {
            $loan->update(['returned_at' => now(), 'returned_to' => $by->id]);
            [$days, $fine] = $this->fineFor($loan->fresh());
            if ($fine > 0) {
                $this->chargeFine($loan, $fine, 'Overdue fine: "' . $copy->item->title . '" returned ' . $days . ' day(s) late', $by);
            }
            $hold = $this->keepForNext($copy);

            return new ReturnResult($loan->fresh(), $fine, $days, $hold);
        });
    }

    /** The borrower lost the copy: they are charged its price (if recorded) plus any overdue fine so far. */
    public function markLost(LibraryLoan $loan, User $by, ?float $charge = null): LibraryLoan
    {
        if (! $loan->isOpen()) {
            throw new RuntimeException('This loan is already closed.');
        }

        return DB::transaction(function () use ($loan, $by, $charge) {
            [, $fine] = $this->fineFor($loan);
            $price = $charge ?? (float) $loan->copy->price;
            $loan->update(['returned_at' => now(), 'returned_to' => $by->id, 'lost' => true]);
            $loan->copy->update(['status' => 'lost']);
            $total = round($fine + $price, 2);
            if ($total > 0) {
                $this->chargeFine($loan, $total, 'Lost book: "' . $loan->copy->item->title . '" (' . $loan->copy->accession_number . ')' . ($fine > 0 ? ', including ₦' . number_format($fine, 2) . ' overdue fine' : ''), $by);
            }
            $this->audit->note('library_lost', 'library', $by, 'marked_lost', $loan, null, ['copy' => $loan->copy->accession_number, 'charge' => $total]);

            return $loan->fresh();
        });
    }

    private function chargeFine(LibraryLoan $loan, float $amount, string $description, User $by): void
    {
        $student = $loan->user->student;
        if ($student) {
            $invoice = $this->charges->charge($student, 'library', 'Library charge', [new ChargeLine($description, $amount, $this->settings->int('fine_item_id') ?: null)], null, $by);
            $loan->update(['fine' => $amount, 'fine_status' => 'invoiced', 'invoice_id' => $invoice->id, 'fine_note' => $description]);
            $this->messenger->notify($loan->user, 'library', 'Library charge', $description . '. ₦' . number_format($amount, 2) . ' has been added to My Fees.', $invoice, route('my.fees.show', $invoice), 'warning', null, ['portal']);
        } else {
            $loan->update(['fine' => $amount, 'fine_status' => 'owed', 'fine_note' => $description]);
        }
    }

    /** Keep a returned copy for the first person waiting for the title, or put it back on the shelf. */
    private function keepForNext(LibraryCopy $copy): ?LibraryHold
    {
        $hold = LibraryHold::where('library_item_id', $copy->library_item_id)->where('status', 'waiting')->orderBy('id')->first();
        if (! $hold) {
            $copy->update(['status' => 'available']);

            return null;
        }
        $until = now()->addDays(max(1, $this->settings->int('hold_days')))->endOfDay();
        $hold->update(['status' => 'ready', 'library_copy_id' => $copy->id, 'ready_at' => now(), 'expires_at' => $until]);
        $copy->update(['status' => 'on_hold']);
        $this->messenger->notify($hold->user, 'library', 'Your library book is ready', '"' . $copy->item->title . '" is being kept for you at the library desk until ' . $until->format('j M Y') . '.', $hold, route('my.library'), 'success', null);

        return $hold;
    }

    public function renew(LibraryLoan $loan, bool $bySelf = false): LibraryLoan
    {
        if (! $loan->isOpen()) {
            throw new RuntimeException('This book has already been returned.');
        }
        if ($bySelf && ! $this->settings->on('self_renew')) {
            throw new RuntimeException('Renew at the library desk.');
        }
        if ($loan->renewals >= $this->settings->int('max_renewals')) {
            throw new RuntimeException('This loan has been renewed the most times allowed. Return the book.');
        }
        if ($loan->isOverdue()) {
            throw new RuntimeException('An overdue book cannot be renewed. Return it to the desk.');
        }
        if (LibraryHold::where('library_item_id', $loan->copy->library_item_id)->where('status', 'waiting')->exists()) {
            throw new RuntimeException('Someone is waiting for this title, so it cannot be renewed.');
        }
        $loan->update(['due_at' => now()->addDays($this->loanDays($loan->user))->endOfDay(), 'renewals' => $loan->renewals + 1, 'reminded_at' => null]);

        return $loan;
    }

    public function placeHold(LibraryItem $item, User $user): LibraryHold
    {
        if ($item->is_reference || $item->kind === 'ebook') {
            throw new RuntimeException('This title is not lent out.');
        }
        if ($item->copies()->where('status', 'available')->exists()) {
            throw new RuntimeException('A copy is on the shelf. Borrow it at the desk.');
        }
        if (! $item->copies()->whereIn('status', ['on_loan', 'on_hold'])->exists()) {
            throw new RuntimeException('The library has no copy of this title that can be lent.');
        }
        if (LibraryHold::where('library_item_id', $item->id)->where('user_id', $user->id)->whereIn('status', ['waiting', 'ready'])->exists()) {
            throw new RuntimeException('You are already waiting for this title.');
        }
        if (LibraryLoan::where('user_id', $user->id)->whereNull('returned_at')->whereHas('copy', fn ($c) => $c->where('library_item_id', $item->id))->exists()) {
            throw new RuntimeException('You already have this title on loan.');
        }
        $standing = $this->standing($user);
        if ($standing->blockedBecause && ! str_starts_with($standing->blockedBecause, 'already has')) {
            throw new RuntimeException('You cannot borrow at the moment: ' . $standing->blockedBecause . '.');
        }

        return LibraryHold::create(['library_item_id' => $item->id, 'user_id' => $user->id, 'status' => 'waiting']);
    }

    public function cancelHold(LibraryHold $hold): void
    {
        if (! in_array($hold->status, ['waiting', 'ready'], true)) {
            throw new RuntimeException('This request is already closed.');
        }
        DB::transaction(function () use ($hold) {
            $copy = $hold->status === 'ready' ? $hold->copy : null;
            $hold->update(['status' => 'cancelled']);
            if ($copy) {
                $this->keepForNext($copy);
            }
        });
    }

    // ---------------------------------------------------------------- fines

    /** A fine paid in cash at the desk (staff, or anyone settling an unbilled fine). */
    public function recordPayment(LibraryLoan $loan, User $by): void
    {
        if ($loan->fine_status !== 'owed') {
            throw new RuntimeException('Only a fine owed at the desk can be recorded here. Billed fines are paid under My Fees.');
        }
        $loan->update(['fine_status' => 'paid', 'fine_settled_at' => now(), 'fine_settled_by' => $by->id]);
        $this->audit->note('library_fine_paid', 'library', $by, 'fine_paid', $loan, null, ['amount' => (float) $loan->fine, 'borrower' => $loan->user->name]);
    }

    public function waive(LibraryLoan $loan, string $reason, User $by): void
    {
        if (! $loan->fineUnpaid()) {
            throw new RuntimeException('There is no unpaid fine on this loan.');
        }
        if ($loan->fine_status === 'invoiced' && ! $this->charges->withdraw($loan->invoice)) {
            throw new RuntimeException('Part of invoice ' . $loan->invoice->reference . ' has been paid, so it cannot be waived here.');
        }
        $loan->update(['fine_status' => 'waived', 'fine_settled_at' => now(), 'fine_settled_by' => $by->id, 'fine_note' => trim($loan->fine_note . ' · Waived: ' . $reason)]);
        $this->audit->note('library_fine_waived', 'library', $by, 'fine_waived', $loan, null, ['amount' => (float) $loan->fine, 'borrower' => $loan->user->name], $reason);
    }

    // ---------------------------------------------------------------- daily

    /** Reminders before the due date, overdue notices (once a week per loan), and holds not collected in time. */
    public function daily(): array
    {
        $soon = $overdue = $expired = 0;
        $before = max(0, $this->settings->int('remind_days'));
        LibraryLoan::with('copy.item', 'user')->whereNull('returned_at')->where('due_at', '<=', now()->addDays($before)->endOfDay())->where('due_at', '>=', now())
            ->whereNull('reminded_at')->get()->each(function (LibraryLoan $l) use (&$soon) {
                $this->messenger->notify($l->user, 'library', 'Library book due soon', '"' . $l->copy->item->title . '" is due back on ' . $l->due_at->format('j M Y') . '.', $l, route('my.library'), 'info', 'library-due-' . $l->id . '-' . $l->due_at->format('Ymd'));
                $l->update(['reminded_at' => now()]);
                $soon++;
            });
        LibraryLoan::with('copy.item', 'user')->whereNull('returned_at')->where('due_at', '<', now())
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhereColumn('reminded_at', '<', 'due_at')->orWhere('reminded_at', '<=', now()->subDays(7)))->get()
            ->each(function (LibraryLoan $l) use (&$overdue) {
                [$days, $fine] = $this->fineFor($l);
                $this->messenger->notify($l->user, 'library', 'Library book overdue', '"' . $l->copy->item->title . '" was due on ' . $l->due_at->format('j M Y') . '. Please return it.' . ($fine > 0 ? ' The fine so far is ₦' . number_format($fine, 2) . '.' : ''), $l, route('my.library'), 'warning', 'library-overdue-' . $l->id . '-' . now()->format('Ymd'));
                $l->update(['reminded_at' => now()]);
                $overdue++;
            });
        LibraryHold::with('copy.item')->where('status', 'ready')->where('expires_at', '<', now())->get()->each(function (LibraryHold $h) use (&$expired) {
            DB::transaction(function () use ($h) {
                $h->update(['status' => 'expired']);
                if ($h->copy) {
                    $this->keepForNext($h->copy);
                }
            });
            $expired++;
        });

        return ['due soon' => $soon, 'overdue' => $overdue, 'holds expired' => $expired];
    }
}
