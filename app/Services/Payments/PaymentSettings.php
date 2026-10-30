<?php

namespace App\Services\Payments;

use App\Support\SettingsGroup;

/** Which online gateways payers may use, their keys (stored encrypted) and how long an unfinished payment is kept open. */
class PaymentSettings extends SettingsGroup
{
    /** Invoice kinds that may need their own Remita service type. Others use the default one. */
    public const REMITA_KINDS = ['fees' => 'School fees', 'acceptance' => 'Acceptance fee', 'screening' => 'Screening fee', 'accommodation' => 'Hostel fees'];

    public const PAYMENT_MODES = ['invoice' => 'On the school fees invoice', 'separate' => 'Separately, on its own invoice'];

    protected function prefix(): string
    {
        return 'payments';
    }

    public function fields(): array
    {
        $fields = [
            'general' => ['General', 'heading', null, 'Students and applicants can pay online with any gateway switched on below. Offline payments recorded by the bursary always work.'],
            'abandon_hours' => ['Hours to wait for an unfinished online payment', 'number', 72, 'An online payment still not confirmed after this long is marked abandoned. Bank-branch payments with a Remita RRR can take a day or two, so do not set this too low.', null, ['required', 'integer', 'min:1', 'max:720']],

            'fee_defaults' => ['How fees are paid', 'heading', null, 'The school default for every fee. Each bill item can choose differently under Bill Items.'],
            'default_payment_mode' => ['Fees are paid', 'select', 'invoice', 'On the invoice: the fee is a line on the student\'s school fees invoice. Separately: the fee gets its own invoice, paid on its own.', self::PAYMENT_MODES, ['sometimes', 'required', 'in:invoice,separate']],
            'default_max_payments' => ['Most payments allowed for one invoice', 'number', 0, '0 means no limit. 1 means the whole invoice must be paid at once. With 3, the third payment must clear the balance.', null, ['sometimes', 'required', 'integer', 'min:0', 'max:12']],

            'paystack' => ['Paystack', 'heading', null, 'Keys are under Settings → API Keys & Webhooks on the Paystack dashboard. Set the webhook URL there to ' . url('webhook/payments/paystack') . '.'],
            'paystack_enabled' => ['Payers may pay with Paystack', 'switch', false, null],
            'paystack_secret_key' => ['Secret key', 'secret', null, 'Starts with sk_test_ while testing and sk_live_ when live.'],

            'flutterwave' => ['Flutterwave', 'heading', null, 'Keys are under Settings → API Keys on the Flutterwave dashboard. Set the webhook URL to ' . url('webhook/payments/flutterwave') . ' and choose a secret hash there.'],
            'flutterwave_enabled' => ['Payers may pay with Flutterwave', 'switch', false, null],
            'flutterwave_secret_key' => ['Secret key', 'secret', null, 'Starts with FLWSECK_TEST- while testing.'],
            'flutterwave_webhook_hash' => ['Webhook secret hash', 'secret', null, 'The same secret hash you typed on the Flutterwave dashboard.'],

            'remita' => ['Remita', 'heading', null, 'Remita gives you a merchant ID, an API key and a service type ID for each kind of payment. Set the notification URL to ' . url('webhook/payments/remita') . '.'],
            'remita_enabled' => ['Payers may pay with Remita', 'switch', false, null],
            'remita_mode' => ['Remita environment', 'select', 'demo', null, ['demo' => 'Demo (testing)', 'live' => 'Live']],
            'remita_merchant_id' => ['Merchant ID', 'text', null, null],
            'remita_api_key' => ['API key', 'secret', null, null],
            'remita_service_type' => ['Service type ID', 'text', null, 'Used for every payment that has no service type of its own below.'],
        ];
        foreach (self::REMITA_KINDS as $kind => $label) {
            $fields["remita_service_type_{$kind}"] = ["Service type ID for {$label}", 'text', null, 'Leave empty to use the one above.'];
        }

        return $fields;
    }

    /** The Remita service type for an invoice kind. */
    public function remitaServiceType(?string $kind): ?string
    {
        return ($kind && isset(self::REMITA_KINDS[$kind]) ? $this->get("remita_service_type_{$kind}") : null) ?: $this->get('remita_service_type');
    }
}
