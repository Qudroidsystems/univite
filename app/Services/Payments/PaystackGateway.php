<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Data\Payments\GatewayResult;
use App\Data\Payments\Payer;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Paystack (card, bank transfer, USSD). Test and live are told apart by the secret key. */
class PaystackGateway implements PaymentGateway
{
    private const API = 'https://api.paystack.co';

    public function __construct(private PaymentSettings $settings)
    {
    }

    public function name(): string
    {
        return 'paystack';
    }

    public function label(): string
    {
        return 'Paystack (card, transfer, USSD)';
    }

    public function isOnline(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->get('paystack_secret_key'));
    }

    private function http()
    {
        return Http::withToken((string) $this->settings->get('paystack_secret_key'))->acceptJson()->timeout(30);
    }

    public function initialize(Payment $payment, Payer $payer, string $returnUrl): GatewayRedirect
    {
        $r = $this->http()->post(self::API . '/transaction/initialize', [
            'email' => $payer->email, 'amount' => (int) round((float) $payment->amount * 100), 'currency' => config('payments.currency'),
            'reference' => $payment->reference, 'callback_url' => $returnUrl,
            'metadata' => ['invoice' => $payment->invoice?->reference, 'payer' => $payer->name],
        ]);
        if (! $r->successful() || ! $r->json('status') || ! $r->json('data.authorization_url')) {
            throw new RuntimeException('Paystack: ' . ($r->json('message') ?? 'HTTP ' . $r->status()));
        }

        return new GatewayRedirect($r->json('data.authorization_url'), gatewayReference: $r->json('data.access_code'));
    }

    public function verify(Payment $payment): GatewayResult
    {
        $r = $this->http()->get(self::API . '/transaction/verify/' . rawurlencode($payment->reference));
        $data = (array) $r->json('data');
        if (! $r->successful() || ! $data) {
            return GatewayResult::pending('Paystack: ' . ($r->json('message') ?? 'HTTP ' . $r->status()));
        }

        return match ($data['status'] ?? null) {
            'success' => new GatewayResult('successful', ((int) $data['amount']) / 100, $data['currency'] ?? null, (string) ($data['id'] ?? ''), $data['channel'] ?? null, $data['gateway_response'] ?? null, $data),
            'failed', 'reversed' => GatewayResult::failed('Paystack: ' . ($data['gateway_response'] ?? $data['status']), $data),
            default => GatewayResult::pending('Paystack: ' . ($data['gateway_response'] ?? $data['status'] ?? 'not paid yet'), $data), // abandoned, ongoing, pending
        };
    }

    public function webhookReferences(Request $request): array
    {
        $signature = (string) $request->header('x-paystack-signature');
        $secret = (string) $this->settings->get('paystack_secret_key');
        if ($secret === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            return [];
        }

        return $request->input('event') === 'charge.success' && $request->input('data.reference') ? [(string) $request->input('data.reference')] : [];
    }
}
