<?php

namespace App\Http\Controllers;

use App\Messaging\Gateway;
use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Which SMS and WhatsApp provider to use, which topics go out by which channel, and the log of every message. */
class MessagingController extends Controller
{
    public function __construct(private OperationAudit $audit)
    {
        $this->middleware('permission:Edit messaging settings')->only('settings', 'update', 'test');
        $this->middleware('permission:View message log')->only('log', 'retry');
    }

    public function settings(): View
    {
        $values = collect(Gateway::KEYS)->mapWithKeys(fn ($k) => [$k => in_array($k, Gateway::SECRETS, true) ? null : Gateway::get($k)]);
        $secretSet = collect(Gateway::SECRETS)->mapWithKeys(fn ($k) => [$k => (bool) Setting::get("messaging.{$k}")]);
        $topics = collect(Messenger::TOPICS)->map(fn ($label, $t) => ['label' => $label, 'push' => Messenger::channelOn($t, 'push'), 'sms' => Messenger::channelOn($t, 'sms'), 'whatsapp' => Messenger::channelOn($t, 'whatsapp')]);
        $devices = \App\Models\DeviceToken::count();

        return view('messaging.settings', ['pagetitle' => 'Messaging settings', 'values' => $values, 'secretSet' => $secretSet, 'topics' => $topics, 'providers' => Gateway::PROVIDERS, 'pushProviders' => \App\Messaging\PushGateway::PROVIDERS, 'devices' => $devices]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'sms_provider' => ['required', Rule::in(array_keys(Gateway::PROVIDERS))], 'whatsapp_provider' => ['required', Rule::in(array_keys(Gateway::PROVIDERS))],
            'country_code' => ['required', 'digits_between:1,4'], 'twilio_sid' => ['nullable', 'string', 'max:64'], 'twilio_from' => ['nullable', 'string', 'max:30'],
            'twilio_whatsapp_from' => ['nullable', 'string', 'max:30'], 'termii_sender' => ['nullable', 'string', 'max:11'], 'webhook_url' => ['nullable', 'url:https', 'max:255'],
            'twilio_token' => ['nullable', 'string', 'max:255'], 'termii_key' => ['nullable', 'string', 'max:255'], 'webhook_token' => ['nullable', 'string', 'max:255'],
            'push_provider' => ['nullable', Rule::in(array_keys(\App\Messaging\PushGateway::PROVIDERS))], 'fcm_project_id' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'fcm_service_account' => ['nullable', 'string', 'max:20000', 'json'],
            'topics' => ['nullable', 'array'],
        ], ['fcm_project_id.regex' => 'The Firebase project id is lower-case letters, digits and dashes, e.g. univite-app.', 'fcm_service_account.json' => 'Paste the whole service account key file (JSON) from Firebase.']);
        $d['push_provider'] ??= 'log';
        if (! empty($d['fcm_service_account'])) {
            $sa = json_decode($d['fcm_service_account'], true);
            if (empty($sa['client_email']) || empty($sa['private_key'])) {
                return back()->withInput()->with('error', 'That is not a Firebase service account key. In Firebase open Project settings, Service accounts, Generate new private key, and paste the whole file.');
            }
        }
        if ($d['push_provider'] === 'fcm' && (! ($d['fcm_project_id'] ?? null) || (! ($d['fcm_service_account'] ?? null) && ! Setting::get('messaging.fcm_service_account')))) {
            return back()->withInput()->with('error', 'Fill in the Firebase project id and service account key for push notifications first.');
        }
        foreach (['sms', 'whatsapp'] as $ch) {
            $p = $d[$ch . '_provider'];
            $missing = match ($p) {
                'twilio' => ! ($d['twilio_sid'] ?? null) || (! ($d['twilio_token'] ?? null) && ! Setting::get('messaging.twilio_token')) || ! ($d[$ch === 'sms' ? 'twilio_from' : 'twilio_whatsapp_from'] ?? null),
                'termii' => ! ($d['termii_key'] ?? null) && ! Setting::get('messaging.termii_key'),
                'webhook' => ! ($d['webhook_url'] ?? null),
                default => false,
            };
            if ($missing) {
                return back()->withInput()->with('error', 'Fill in the ' . Gateway::PROVIDERS[$p] . ' details for ' . ($ch === 'sms' ? 'text messages' : 'WhatsApp') . ' first.');
            }
        }
        Gateway::put(collect($d)->except('topics')->all());
        foreach (array_keys(Messenger::TOPICS) as $t) {
            foreach (['push', 'sms', 'whatsapp'] as $ch) {
                Setting::put("messaging.topic.{$t}.{$ch}", ! empty($d['topics'][$t][$ch]) ? '1' : '0');
            }
        }
        // Secrets are never written to the audit trail.
        $this->audit->note('messaging_settings', 'messaging', $request->user(), 'messaging settings changed', null, null, ['sms' => $d['sms_provider'], 'whatsapp' => $d['whatsapp_provider'], 'push' => $d['push_provider']]);

        return back()->with('success', 'Messaging settings saved.');
    }

    public function test(Request $request, Messenger $messenger): RedirectResponse
    {
        $d = $request->validate(['channel' => ['required', 'in:sms,whatsapp'], 'to' => ['required', 'string', 'max:30']]);
        $to = Gateway::normalise($d['to']);
        if (! $to) {
            return back()->with('error', 'That phone number does not look complete.');
        }
        $status = $messenger->queue($request->user(), $d['channel'], $to, 'Test message from ' . (\App\Support\Institution::get('short_name') ?: config('app.name')) . ' portal.', 'test');
        $m = OutboundMessage::latest('id')->first();

        return back()->with($status === 'failed' ? 'error' : 'success', match ($status) {
            'sent' => 'Sent to ' . $to . '. Check the phone.',
            'logged' => 'No provider is set up for this channel, so the message was only written to the log.',
            default => 'The provider refused it: ' . ($m?->error ?? 'no reason given'),
        });
    }

    public function log(Request $request): View
    {
        $q = OutboundMessage::with('user')->latest('id')
            ->when($request->filled('channel'), fn ($w) => $w->where('channel', $request->channel))
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->status))
            ->when($request->filled('topic'), fn ($w) => $w->where('topic', $request->topic))
            ->when(trim((string) $request->q) !== '', fn ($w) => $w->where(fn ($x) => $x->where('recipient', 'like', "%{$request->q}%")->orWhere('body', 'like', "%{$request->q}%")));

        return view('messaging.log', [
            'pagetitle' => 'Message log', 'messages' => $q->paginate(50)->withQueryString(), 'topics' => Messenger::TOPICS,
            'counts' => OutboundMessage::where('created_at', '>=', now()->subDays(30))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function retry(Messenger $messenger): RedirectResponse
    {
        $n = $messenger->retryFailed();

        return back()->with('success', "{$n} message(s) went through on retry.");
    }
}
