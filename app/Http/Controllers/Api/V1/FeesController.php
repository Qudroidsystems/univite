<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiInvoice;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's invoices, payments and receipts. Paying opens the portal's pay page (pay_url) in the browser. */
class FeesController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $invoices = $this->student($request)->invoices()->with('session')->where('status', '!=', 'cancelled')->latest('id')->get();

        return $this->ok($invoices->map(fn ($i) => ApiInvoice::from($i)->toArray())->values()->all(), ['outstanding' => round($invoices->sum(fn ($i) => $i->outstanding), 2)]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($invoice->payable_type === $student::class && $invoice->payable_id === $student->id && $invoice->status !== 'cancelled', 404);

        return $this->ok(ApiInvoice::from($invoice->load('session', 'lines', 'payments'), true)->toArray());
    }
}
