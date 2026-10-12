<?php

namespace App\Http\Controllers;

use App\Data\Lookups\LookupOption;
use App\Data\Lookups\LookupQuery;
use App\Services\Lookups\LookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Options for a dropdown, fetched when it is opened (see public/js/univite-lookups.js). */
class LookupController extends Controller
{
    public function __construct(private readonly LookupService $lookups)
    {
    }

    public function __invoke(Request $request, string $list): JsonResponse
    {
        $options = $this->lookups->options($list, LookupQuery::fromRequest($request));
        abort_if($options === null, 404);

        return response()->json(['options' => array_map(fn (LookupOption $o) => $o->toArray(), $options)])
            ->header('Cache-Control', 'no-store');
    }
}
