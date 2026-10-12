<?php

namespace App\Http\Controllers\Library;

use App\Data\Library\ItemInput;
use App\Http\Controllers\Controller;
use App\Models\LibraryCopy;
use App\Models\LibraryItem;
use App\Services\Library\LibraryService;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** The library catalogue: titles, their copies, and importing a catalogue from a spreadsheet. */
class CatalogueController extends Controller
{
    public function __construct(private LibraryService $library, private SpreadsheetReader $reader)
    {
        $this->middleware('permission:Edit library catalogue|Issue library loans|View library loans')->only('index', 'show');
        $this->middleware('permission:Edit library catalogue')->except('index', 'show');
    }

    public function index(Request $request): View
    {
        $items = LibraryItem::search($request->query('q'))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->withCount(['copies', 'copies as available_count' => fn ($c) => $c->where('status', 'available'), 'holds as waiting_count' => fn ($h) => $h->where('status', 'waiting')])
            ->orderBy('title')->paginate(30)->withQueryString();

        return view('library.catalogue', ['pagetitle' => 'Library catalogue', 'items' => $items,
            'totals' => ['titles' => LibraryItem::count(), 'copies' => LibraryCopy::whereNotIn('status', ['lost', 'withdrawn'])->count(), 'out' => LibraryCopy::where('status', 'on_loan')->count()]]);
    }

    public function create(): View
    {
        return view('library.item-form', ['pagetitle' => 'New title', 'item' => new LibraryItem(['kind' => 'book'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(ItemInput::rules() + ['copies' => ['nullable', 'integer', 'min:0', 'max:500'], 'location' => ['nullable', 'string', 'max:255'], 'price' => ['nullable', 'numeric', 'min:0']]);
        $item = $this->library->createItem(ItemInput::fromArray($d), $request->user(), (int) ($d['copies'] ?? 0), $d['location'] ?? null, isset($d['price']) ? (float) $d['price'] : null);

        return redirect()->route('library.items.show', $item)->with('success', '"' . $item->title . '" added with ' . $item->copies()->count() . ' cop(ies).');
    }

    public function show(LibraryItem $item): View
    {
        $item->load(['copies.openLoan.user', 'holds' => fn ($h) => $h->whereIn('status', ['waiting', 'ready'])->with('user')->orderBy('id')]);

        return view('library.item', ['pagetitle' => $item->title, 'item' => $item]);
    }

    public function edit(LibraryItem $item): View
    {
        return view('library.item-form', ['pagetitle' => 'Title details', 'item' => $item]);
    }

    public function update(Request $request, LibraryItem $item): RedirectResponse
    {
        $this->library->updateItem($item, ItemInput::fromArray($request->validate(ItemInput::rules())));

        return redirect()->route('library.items.show', $item)->with('success', 'Saved.');
    }

    public function destroy(LibraryItem $item): RedirectResponse
    {
        if ($item->copies()->whereHas('loans')->exists()) {
            return back()->with('error', 'This title has been lent out before, so it stays in the catalogue. Withdraw its copies instead.');
        }
        $item->delete();

        return redirect()->route('library.items.index')->with('success', 'Title removed from the catalogue.');
    }

    public function addCopies(Request $request, LibraryItem $item): RedirectResponse
    {
        $d = $request->validate(['count' => ['nullable', 'integer', 'min:1', 'max:500'], 'accessions' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'], 'price' => ['nullable', 'numeric', 'min:0']]);
        $typed = preg_split('/[\s,;]+/', (string) ($d['accessions'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (! $typed && empty($d['count'])) {
            return back()->with('error', 'Say how many copies, or type their accession numbers.');
        }
        try {
            $n = $this->library->addCopies($item, (int) ($d['count'] ?? 0), $typed, $d['location'] ?? null, isset($d['price']) ? (float) $d['price'] : null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $n . ' cop(ies) added.');
    }

    public function updateCopy(Request $request, LibraryCopy $copy): RedirectResponse
    {
        $d = $request->validate(['location' => ['nullable', 'string', 'max:255'], 'price' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['available', 'repair', 'lost', 'withdrawn', $copy->status])]]);
        if ($d['status'] !== $copy->status && in_array($copy->status, ['on_loan', 'on_hold'], true)) {
            return back()->with('error', 'Copy ' . $copy->accession_number . ' is ' . strtolower(LibraryCopy::STATUSES[$copy->status]) . '. Check it in at the desk first.');
        }
        $copy->update($d);

        return back()->with('success', 'Copy ' . $copy->accession_number . ' updated.');
    }

    public function importForm(): View
    {
        return view('library.import', ['pagetitle' => 'Catalogue import']);
    }

    public function import(Request $request): RedirectResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'], 'location' => ['nullable', 'string', 'max:255']]);
        try {
            $table = $this->reader->read($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());
            $result = $this->library->import($table, $request->user(), $d['location'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('library.items.index')->with('success', $result->titles . ' new title(s) and ' . $result->copies . ' cop(ies) added.')
            ->with('import_errors', $result->errors);
    }
}
