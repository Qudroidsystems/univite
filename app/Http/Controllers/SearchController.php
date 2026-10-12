<?php

namespace App\Http\Controllers;

use App\Data\Search\SearchHit;
use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The search box in the top bar (Ctrl+K / ⌘K): records the person may see. Pages are searched in the browser. */
class SearchController extends Controller
{
    public function __construct(private GlobalSearch $search)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $hits = $this->search->find($request->user(), mb_substr((string) $request->query('q'), 0, 100));

        return response()->json(['results' => array_map(fn (SearchHit $h) => $h->toArray(), $hits)]);
    }
}
