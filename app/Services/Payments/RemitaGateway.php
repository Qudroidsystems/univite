<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Data\Payments\GatewayResult;
use App\Data\Payments\Payer;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Remita. Each payment first gets an RRR (Remita Retrieval Reference); the payer then pays it online on the Remita
 * page, or later at a bank branch quoting the RRR. Branch payments are confirmed by Remita's notification or on the
 * schedule.
 */
class RemitaGateway implements PaymentGateway
{
    private const PAID = ['00', '01'];
    private const FAILED = ['02']; // anything else not paid stays pending until it is paid or abandoned

    public function __construct(private PaymentSettings $settings)
    {
    }

    public function name(): string
    {
        return 'remita';
    }

    public function label(): string
    {
        return 'Remita (card, bank branch with RRR, transfer)';
    }

    public function isOnline(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return filled($this->settings->get('remita_merchant_id')) && filled($this->settings->get('remita_api_key')) && filled($this->settings->get('remita_service_type'));
    }

    private function base(): string
    {
        return $this->settings->get('remita_mode') === 'live' ? 'https://login.remita.net' : 'https://remitademo.net';
    }

    /** Remita answers some calls as jsonp ({...}); take the JSON out. */
    private function decode(string $body): array
    {
        $json = preg_match('/\{.*\}/s', $body, $m) ? $m[0] : $body;

        return (array) json_decode($json, true);
    }

    public function initialize(Payment $payment, Payer $payer, string $returnUrl): GatewayRedirect
    {
        $merchant = (string) $this->settings->get('remita_merchant_id');
        $key = (string) $this->settings->get('remita_api_key');
        $service = (string) $this->settings->remitaServiceType($payment->invoice?->kind);
        $amount = number_format((float) $payment->amount, 2, '.', '');

        $rrr = $payment->gateway_reference;
        if (! $rrr) {
            $hash = hash('sha512', $merchant . $service . $payment->reference . $amount . $key);
            $r = Http::withHeaders(['Authorization' => "remitaConsumerKey={$merchant},remitaConsumerToken={$hash}"])->timeout(30)
                ->post($this->base() . '/remita/exapp/api/v1/send/api/echannelsvc/merchant/api/paymentinit', array_filter([
                    'serviceTypeId' => $service, 'amount' => $amount, 'orderId' => $payment->reference,
                    'payerName' => $payer->name, 'payerEmail' => $payer->email, 'payerPhone' => $payer->phone,
                    'description' => (string) $payment->invoice?->title,
                ]));
            $data = $this->decode($r->body());
            $rrr = $data['RRR'] ?? null;
            if (! $r->successful() || ! $rrr) {
                throw new RuntimeException('Remita: ' . ($data['status'] ?? $data['statusMessage'] ?? 'HTTP ' . $r->status()));
            }
        }

        return new GatewayRedirect($this->base() . '/remita/ecomm/finalize.reg', 'POST', [
            'merchantId' => $merchant, 'hash' => hash('sha512', $merchant . $rrr . $key), 'rrr' => $rrr, 'responseurl' => $returnUrl,
        ], $rrr);
    }

    public function verify(Payment $payment): GatewayResult
    {
        $rrr = $payment->gateway_reference;
        if (! $rrr) {
            return GatewayResult::failed('Remita: no RRR was generated for this payment.');
        }
        $merchant = (string) $this->settings->get('remita_merchant_id');
        $key = (string) $this->settings->get('remita_api_key');
        $hash = hash('sha512', $rrr . $key . $merchant);
        $r = Http::withHeaders(['Authorization' => "remitaConsumerKey={$merchant},remitaConsumerToken={$hash}"])->acceptJson()->timeout(30)
            ->get($this->base() . "/remita/exapp/api/v1/send/api/echannelsvc/{$merchant}/{$rrr}/{$hash}/status.reg");
        $data = $this->decode($r->body());
        if (! $r->successful() || ! $data) {
            return GatewayResult::pending('Remita: HTTP ' . $r->status());
        }
        $status = (string) ($data['status'] ?? '');
        $message = 'Remita: ' . ($data['message'] ?? $status);

        return match (true) {
            in_array($status, self::PAID, true) => new GatewayResult('successful', isset($data['amount']) ? (float) $data['amount'] : null, 'NGN', $rrr, $data['channel'] ?? null, $message, $data),
            in_array($status, self::FAILED, true) => GatewayResult::failed($message, $data),
            default => GatewayResult::pending($message, $data), // 021 pending, 025 RRR generated but not paid
        };
    }

    /** Remita posts a list of notifications without a signature; each is checked with Remita before anything changes. */
    public function webhookReferences(Request $request): array
    {
        $items = $request->json()->all();
        $items = array_is_list($items) ? $items : [$items];

        return collect($items)->flatMap(fn ($i) => array_filter([$i['orderRef'] ?? null, $i['rrr'] ?? null]))->map(fn ($v) => (string) $v)->unique()->values()->all();
    }
}
