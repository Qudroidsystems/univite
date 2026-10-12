<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Data\Payments\GatewayResult;
use App\Data\Payments\Payer;
use App\Data\Payments\ReconcileResult;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paying an invoice online: start a payment with a gateway, then confirm it when the payer comes back, when the
 * gateway calls the webhook, or on the schedule. A payment only counts once the gateway itself confirms it was paid
 * in full; what the payer's browser or a webhook says is never trusted on its own.
 */
class OnlinePaymentService
{
    public function __construct(private Gateways $gateways, private InvoiceService $invoices, private PaymentSettings $settings)
    {
    }

    /** @return array<string, string> name => label of the gateways a payer may choose now */
    public function choices(): array
    {
        return array_map(fn (PaymentGateway $g) => $g->label(), $this->gateways->enabled());
    }

    /** Why this invoice cannot be paid online now, or null when it can. */
    public function blocker(Invoice $invoice): ?string
    {
        return match (true) {
            $invoice->status === 'cancelled' => 'This invoice has been cancelled.',
            $invoice->outstanding <= 0 => 'This invoice is fully paid.',
            $invoice->windowState() === 'not_yet' => 'Payment opens on ' . $invoice->opens_at->format('j M Y, g:i A') . '.',
            $invoice->windowState() === 'closed' => 'The payment window closed on ' . $invoice->closes_at->format('j M Y, g:i A') . '. Please contact the bursary.',
            ! $this->choices() => 'Online payment is not set up yet. Please pay as the bursary directs.',
            default => null,
        };
    }

    /** Start paying $amount of an invoice with a gateway; returns where to send the payer. */
    public function start(Invoice $invoice, string $gateway, float $amount, callable $returnUrl): GatewayRedirect
    {
        $chosen = $this->gateways->enabled()[$gateway] ?? throw new RuntimeException('That payment method is not available.');
        $payer = $this->payer($invoice);

        // A payer who paid but did not come back must not pay twice: settle earlier attempts first.
        foreach ($invoice->payments()->where('status', 'pending')->where('gateway', '!=', 'manual')->get() as $earlier) {
            $this->confirm($earlier);
        }

        $payment = DB::transaction(function () use ($invoice, $gateway, $amount) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);
            if ($why = $this->blocker($invoice)) {
                throw new RuntimeException($why);
            }
            if ($amount < $invoice->minimumPayment() - 0.001) {
                throw new RuntimeException('Pay at least ₦' . number_format($invoice->minimumPayment(), 2) . ' now.');
            }
            if ($amount > $invoice->outstanding + 0.001) {
                throw new RuntimeException('That is more than the ₦' . number_format($invoice->outstanding, 2) . ' still owed on this invoice.');
            }

            return Payment::create([
                'invoice_id' => $invoice->id, 'reference' => 'PAY-' . strtoupper(Str::random(12)), 'gateway' => $gateway,
                'amount' => $amount, 'status' => 'pending',
            ]);
        });

        try {
            $redirect = $chosen->initialize($payment->load('invoice'), $payer, $returnUrl($payment));
        } catch (\Throwable $e) {
            $payment->update(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 500)]);
            Log::warning("Payment {$payment->reference} could not start: {$e->getMessage()}");

            throw new RuntimeException('The payment page could not be opened. Please try again shortly or choose another method.');
        }
        if ($redirect->gatewayReference && $gateway === 'remita') {
            $payment->update(['gateway_reference' => $redirect->gatewayReference]);
        }

        return $redirect;
    }

    /**
     * Ask the gateway about a payment that is not yet settled and record the answer. Abandoned payments are
     * checked too, so money paid late (for example at a bank with a Remita RRR) is still counted.
     */
    public function confirm(Payment $payment): Payment
    {
        if (! in_array($payment->status, ['pending', 'abandoned'], true) || $payment->gateway === 'manual') {
            return $payment;
        }
        $result = $this->gateways->get($payment->gateway)->verify($payment);

        if ($result->status === 'successful') {
            if ($problem = $this->mismatch($payment, $result)) {
                $payment->update(['status' => 'failed', 'note' => $problem, 'payload' => $result->payload]);
                Log::warning("Payment {$payment->reference}: {$problem}");

                return $payment->fresh();
            }

            return $this->invoices->markSuccessful($payment, $result->gatewayReference ?: null, $result->payload, $result->channel ? mb_substr($result->channel, 0, 30) : null);
        }
        if ($result->status === 'failed' && $payment->status === 'pending') {
            $payment->update(['status' => 'failed', 'note' => mb_substr((string) $result->message, 0, 500), 'payload' => $result->payload ?: $payment->payload]);
        }

        return $payment->fresh();
    }

    /** Why a payment the gateway calls successful must not be counted, or null when it is fine. */
    private function mismatch(Payment $payment, GatewayResult $result): ?string
    {
        if ($result->currency && strtoupper($result->currency) !== config('payments.currency')) {
            return "Paid in {$result->currency}, not " . config('payments.currency') . '. Check with the gateway before recording it by hand.';
        }
        if ($result->amount !== null && $result->amount + 0.009 < (float) $payment->amount) {
            return 'The gateway says ₦' . number_format($result->amount, 2) . ' was paid, not ₦' . number_format((float) $payment->amount, 2) . '. Check with the gateway before recording it by hand.';
        }

        return null;
    }

    /** A gateway called the webhook: check each payment it names with the gateway. Returns how many are now paid. */
    public function webhook(string $gateway, Request $request): int
    {
        $refs = $this->gateways->get($gateway)->webhookReferences($request);
        if (! $refs) {
            return 0;
        }

        return Payment::where('gateway', $gateway)->whereIn('status', ['pending', 'abandoned'])
            ->where(fn ($q) => $q->whereIn('reference', $refs)->orWhereIn('gateway_reference', $refs))
            ->get()->filter(fn (Payment $p) => $this->confirm($p)->status === 'successful')->count();
    }

    /** Check every online payment still waiting, and mark ones left unfinished too long as abandoned. */
    public function reconcile(): ReconcileResult
    {
        $confirmed = $failed = $abandoned = $waiting = 0;
        $cutoff = now()->subHours(max(1, $this->settings->int('abandon_hours')));

        Payment::where('status', 'pending')->where('gateway', '!=', 'manual')->where('created_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->each(function (Payment $p) use ($cutoff, &$confirmed, &$failed, &$abandoned, &$waiting) {
                try {
                    $p = $this->confirm($p);
                } catch (\Throwable $e) {
                    Log::warning("Could not check payment {$p->reference}: {$e->getMessage()}");
                }
                if ($p->status === 'successful') {
                    $confirmed++;
                } elseif ($p->status === 'failed') {
                    $failed++;
                } elseif ($p->created_at->lt($cutoff)) {
                    $p->update(['status' => 'abandoned', 'note' => 'Not completed within ' . $this->settings->int('abandon_hours') . ' hours.']);
                    $abandoned++;
                } else {
                    $waiting++;
                }
            });

        return new ReconcileResult($confirmed, $failed, $abandoned, $waiting);
    }

    /** Where the payer goes after paying: their own fees page. */
    public function payerPage(Payment $payment): string
    {
        $invoice = $payment->invoice;

        return $invoice->payable_type === Applicant::class ? route('apply.dashboard') : route('my.fees.show', $invoice->id);
    }

    private function payer(Invoice $invoice): Payer
    {
        $person = $invoice->payable;
        $email = $person instanceof Student ? $person->user?->email : $person?->email;
        if (! $email) {
            throw new RuntimeException('An email address is needed to pay online. Please ask the ICT unit to add one to your account.');
        }

        return new Payer((string) $person->full_name, $email, $person->phone_number ?? null);
    }
}
