<?php

use App\Models\Invoice;
use App\Models\LibraryCopy;
use App\Models\LibraryHold;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Services\Library\LibraryService;
use App\Services\Library\LibrarySettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    campusWorld($this);
    $this->ada = makeStudent($this, 'Ada');
    $this->bayo = makeStudent($this, 'Bayo', 'male');
    $this->librarian = makeStaff($this, 'Head Librarian', null, ['Edit library catalogue', 'Issue library loans', 'View library loans', 'Record library fine payments', 'Waive library fines', 'Set library policy']);
    app(LibrarySettings::class)->save(['fine_per_day' => 50, 'student_days' => 14, 'student_limit' => 2, 'accession_prefix' => 'AFL']);
});

afterEach(fn () => Carbon::setTestNow());

function bookWithCopies($test, int $copies = 1, array $extra = []): LibraryItem
{
    $test->actingAs($test->librarian)->post(route('library.items.store'), $extra + ['title' => 'Organic Chemistry', 'kind' => 'book', 'authors' => 'Morrison, R.', 'copies' => $copies, 'price' => 8000])->assertRedirect();

    return LibraryItem::latest('id')->first();
}

it('lends and takes back a book, fining a late return onto the student\'s fees', function () {
    $book = bookWithCopies($this);
    $copy = $book->copies()->sole();
    expect($copy->accession_number)->toBe('AFL000001');

    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001'])->assertSessionHas('success', fn ($m) => str_contains($m, 'due back'));
    expect($copy->fresh()->status)->toBe('on_loan');
    $this->post(route('library.issue'), ['borrower' => $this->bayo->matric_number, 'accession' => 'AFL000001'])->assertSessionHas('error', fn ($m) => str_contains($m, 'on loan'));

    $this->actingAs($this->ada->user)->get(route('my.library'))->assertOk()->assertSee('Organic Chemistry')->assertSee('Due Mon 16 Nov 2026');

    Carbon::setTestNow('2026-11-20 10:00:00'); // four days late
    $this->actingAs($this->librarian)->post(route('library.return'), ['accession' => 'AFL000001'])->assertSessionHas('warning', fn ($m) => str_contains($m, '4 day(s) late: fine ₦200.00, added to their fees'));
    $loan = LibraryLoan::sole();
    expect($loan->fine_status)->toBe('invoiced')->and($loan->invoice->kind)->toBe('library')->and((float) $loan->invoice->total)->toBe(200.0)
        ->and($copy->fresh()->status)->toBe('available');

    // The unpaid fine stops further borrowing until it is paid.
    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001'])->assertSessionHas('error', fn ($m) => str_contains($m, 'owes ₦200.00'));
    $loan->invoice->update(['status' => 'paid', 'amount_paid' => 200]);
    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001'])->assertSessionHas('success');
});

it('keeps a returned copy for the first person waiting, and renewals are refused while someone waits', function () {
    $book = bookWithCopies($this);
    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001']);
    $loan = LibraryLoan::sole();

    $this->actingAs($this->ada->user)->post(route('my.library.renew', $loan))->assertSessionHas('success');
    expect($loan->fresh()->renewals)->toBe(1);

    $this->actingAs($this->bayo->user)->post(route('my.library.hold', $book))->assertSessionHas('success');
    $this->actingAs($this->ada->user)->post(route('my.library.renew', $loan))->assertSessionHas('error', fn ($m) => str_contains($m, 'Someone is waiting'));

    $this->actingAs($this->librarian)->post(route('library.return'), ['accession' => 'AFL000001'])->assertSessionHas('warning', fn ($m) => str_contains($m, 'reserved for Student Bayo'));
    expect(LibraryCopy::sole()->status)->toBe('on_hold')->and(LibraryHold::sole()->status)->toBe('ready');

    // Only Bayo can take it now.
    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001'])->assertSessionHas('error', fn ($m) => str_contains($m, 'being kept for'));
    $this->post(route('library.issue'), ['borrower' => $this->bayo->matric_number, 'accession' => 'AFL000001'])->assertSessionHas('success');
    expect(LibraryHold::sole()->status)->toBe('fulfilled');
});

it('releases an uncollected hold to the shelf, and sends due and overdue reminders', function () {
    $book = bookWithCopies($this, 2);
    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001']);
    $this->post(route('library.issue'), ['borrower' => $this->bayo->matric_number, 'accession' => 'AFL000002']);
    $staff = makeStaff($this, 'Grace Lecturer');
    app(LibraryService::class)->placeHold($book, $staff);
    $this->post(route('library.return'), ['accession' => 'AFL000002']);

    Carbon::setTestNow('2026-11-15 08:00:00'); // Ada's book is due tomorrow; the hold ran out on the 5th
    $sent = app(LibraryService::class)->daily();
    expect($sent)->toBe(['due soon' => 1, 'overdue' => 0, 'holds expired' => 1])
        ->and(LibraryCopy::where('accession_number', 'AFL000002')->value('status'))->toBe('available');

    Carbon::setTestNow('2026-11-18 08:00:00');
    expect(app(LibraryService::class)->daily()['overdue'])->toBe(1)->and(app(LibraryService::class)->daily()['overdue'])->toBe(0); // once a week
});

it('charges a lost book at its price, and lets staff fines be paid or waived at the desk', function () {
    bookWithCopies($this);
    $lecturer = makeStaff($this, 'Grace Lecturer');
    $this->post(route('library.issue'), ['borrower' => $this->ada->matric_number, 'accession' => 'AFL000001']);
    $loan = LibraryLoan::sole();
    $this->post(route('library.loans.lost', $loan), ['charge' => 8000])->assertSessionHas('success', fn ($m) => str_contains($m, '₦8,000.00'));
    expect(LibraryCopy::sole()->status)->toBe('lost')->and($loan->fresh()->invoice->total)->toEqual(8000);

    // A late staff return: the fine is paid in cash at the desk.
    bookWithCopies($this, 1, ['title' => 'Teaching Methods']);
    $this->post(route('library.issue'), ['borrower' => $lecturer->staffProfile->staff_number, 'accession' => 'AFL000002'])->assertSessionHas('success');
    Carbon::setTestNow('2026-12-05 10:00:00'); // 30-day loan, due 2 Dec: 3 days late
    $this->post(route('library.return'), ['accession' => 'AFL000002'])->assertSessionHas('warning', fn ($m) => str_contains($m, 'to be paid at the desk'));
    $staffLoan = LibraryLoan::where('user_id', $lecturer->id)->sole();
    expect($staffLoan->fine_status)->toBe('owed')->and((float) $staffLoan->fine)->toBe(150.0);
    $this->get(route('library.fines'))->assertOk()->assertSee('Grace Lecturer');
    $this->post(route('library.fines.paid', $staffLoan))->assertSessionHas('success');
    expect($staffLoan->fresh()->fine_status)->toBe('paid');

    // Waiving a student's billed fine cancels the invoice.
    $this->post(route('library.fines.waive', $loan), ['reason' => 'Book found and returned'])->assertSessionHas('success');
    expect($loan->fresh()->fine_status)->toBe('waived')->and($loan->fresh()->invoice->status)->toBe('cancelled');
});

it('imports a catalogue from a spreadsheet and keeps the desk to library staff', function () {
    $csv = "Title,Author,ISBN,Year,Call number,Copies\nIntroduction to Physics,\"Okafor, C.\",978-0-12-345678-9,2019,QC21 .O4,3\nIntroduction to Physics,,9780123456789,,,2\nBad Year Book,,,19x9,,1\n,,,,,\n";
    $file = UploadedFile::fake()->createWithContent('cat.csv', $csv);
    $this->actingAs($this->librarian)->post(route('library.import.store'), ['file' => $file])
        ->assertSessionHas('success', '2 new title(s) and 6 cop(ies) added.')->assertSessionHas('import_errors', fn ($e) => count($e) === 1 && str_contains($e[0], 'not a year'));
    expect(LibraryItem::where('isbn', '9780123456789')->sole()->copies()->count())->toBe(5);

    $this->get(route('library.items.index', ['q' => 'physics']))->assertOk()->assertSee('Introduction to Physics');
    $this->get(route('library.desk', ['borrower' => $this->ada->matric_number]))->assertOk()->assertSee('May borrow');
    $this->get(route('library.loans'))->assertOk();
    $this->get(route('library.settings'))->assertOk();
    $this->actingAs($this->ada->user)->get(route('library.desk'))->assertForbidden();
    $this->get(route('my.library', ['q' => 'physics']))->assertOk()->assertSee('5 on the shelf');
});
