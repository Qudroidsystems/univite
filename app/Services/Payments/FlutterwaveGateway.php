<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Data\Payments\GatewayResult;
use App\Data\Payments\Payer;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Flutterwave Standard (card, bank transfer, USSD). Test and live are told apart by the secret key. */
class FlutterwaveGateway implements PaymentGateway
{
    private const API = 'https://api.flutterwave.com/v3';

    public function __construct(private PaymentSettings $settings)
    {
    }

    public function name(): string
    {
        return 'flutterwave';
    }

    public function label(): string
    {
        return 'Flutterwave (card, transfer, USSD)';
    }

    public function isOnline(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->get('flutterwave_secret_key'));
    }

    private function http()
    {
        return Http::withToken((string) $this->settings->get('flutterwave_secret_key'))->acceptJson()->timeout(30);
    }

    public function initialize(Payment $payment, Payer $payer, string $returnUrl): GatewayRedirect
    {
        $r = $this->http()->post(self::API . '/payments', [
            'tx_ref' => $payment->reference, 'amount' => round((float) $payment->amount, 2), 'currency' => config('payments.currency'),
            'redirect_url' => $returnUrl,
            'customer' => array_filter(['email' => $payer->email, 'name' => $payer->name, 'phonenumber' => $payer->phone]),
            'customizations' => ['title' => \App\Support\Institution::get('short_name') ?: config('app.name'), 'description' => (string) $payment->invoice?->title],
            'meta' => ['invoice' => $payment->invoice?->reference],
        ]);
        if (! $r->successful() || $r->json('status') !== 'success' || ! $r->json('data.link')) {
            throw new RuntimeException('Flutterwave: ' . ($r->json('message') ?? 'HTTP ' . $r->status()));
        }

        return new GatewayRedirect($r->json('data.link'));
    }

    public function verify(Payment $payment): GatewayResult
    {
        $r = $this->http()->get(self::API . '/transactions/verify_by_reference', ['tx_ref' => $payment->reference]);
        $data = (array) $r->json('data');
        if (! $r->successful() || ! $data) {
            return GatewayResult::pending('Flutterwave: ' . ($r->json('message') ?? 'HTTP ' . $r->status()));
        }

        return match ($data['status'] ?? null) {
            'successful' => new GatewayResult('successful', (float) $data['amount'], $data['currency'] ?? null, (string) ($data['id'] ?? ''), $data['payment_type'] ?? null, $data['processor_response'] ?? null, $data),
            'failed' => GatewayResult::failed('Flutterwave: ' . ($data['processor_response'] ?? 'failed'), $data),
            default => GatewayResult::pending('Flutterwave: ' . ($data['status'] ?? 'not paid yet'), $data),
        };
    }

    public function webhookReferences(Request $request): array
    {
        $hash = (string) $this->settings->get('flutterwave_webhook_hash');
        if ($hash === '' || ! hash_equals($hash, (string) $request->header('verif-hash'))) {
            return [];
        }
        $ref = $request->input('data.tx_ref') ?? $request->input('txRef');

        return $ref ? [(string) $ref] : [];
    }
}
