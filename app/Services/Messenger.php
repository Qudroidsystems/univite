<?php

namespace App\Services;

use App\Messaging\Gateway;
use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PortalNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place notices go out from: a portal notice under the bell, plus SMS and WhatsApp when switched on for the
 * topic and the person has a number and has not opted out. Every SMS/WhatsApp is logged in outbound_messages; a
 * dedupe key stops the same reminder going twice, and failed messages are retried by `messages:retry`.
 * Emails with documents (letters, slips) go through DocumentMailer.
 */
class Messenger
{
    public const TOPICS = [
        'leave' => 'Leave applications, decisions and reminders',
        'leave_approvals' => 'Leave waiting for an approver, and escalations',
        'timetable' => 'Published timetables and changes',
        'seating' => 'Examination seat numbers',
        'venues' => 'Venue closures that affect timetables',
        'payslip' => 'Payslips ready',
    ];

    public function __construct(private Gateway $gateway)
    {
    }

    public static function channelOn(string $topic, string $channel): bool
    {
        return Setting::get("messaging.topic.{$topic}.{$channel}", $channel === 'sms' && str_starts_with($topic, 'leave') ? '1' : '0') === '1';
    }

    /**
     * @param  array<int, string>|null  $channels  defaults to every channel switched on for the topic
     * @return array<string, string> channel => status
     */
    public function notify(User $user, string $topic, string $title, string $body, ?Model $subject = null, ?string $url = null, string $level = 'info', ?string $dedupe = null, ?array $channels = null): array
    {
        $out = [];
        $channels ??= ['portal', 'sms', 'whatsapp'];

        DB::afterCommit(function () use ($user, $topic, $title, $body, $subject, $url, $level, $dedupe, $channels, &$out) {
            if (in_array('portal', $channels, true)) {
                $user->notify(new PortalNotice($title, $body, $level, $url));
            }
            foreach (['sms' => [$user->phone_number, $user->notify_sms ?? true], 'whatsapp' => [$user->whatsapp_number ?: $user->phone_number, $user->notify_whatsapp ?? true]] as $channel => [$number, $wanted]) {
                if (! in_array($channel, $channels, true) || ! self::channelOn($topic, $channel) || ! $wanted || ! ($to = Gateway::normalise($number))) {
                    continue;
                }
                $out[$channel] = $this->queue($user, $channel, $to, Str::limit($title . ': ' . $body, $channel === 'sms' ? 459 : 1500, '…'), $topic, $subject, $dedupe ? "{$dedupe}:{$channel}" : null);
            }
        });

        return $out;
    }

    public function queue(?User $user, string $channel, string $to, string $body, string $topic, ?Model $subject = null, ?string $dedupe = null): string
    {
        if ($dedupe && OutboundMessage::where('dedupe_key', $dedupe)->exists()) {
            return 'duplicate';
        }
        $m = OutboundMessage::create([
            'user_id' => $user?->id, 'channel' => $channel, 'recipient' => $to, 'body' => $body, 'topic' => $topic,
            'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(), 'dedupe_key' => $dedupe,
        ]);

        return $this->deliver($m);
    }

    public function deliver(OutboundMessage $m): string
    {
        [$status, $ref, $error] = $this->gateway->send($m->channel, $m->recipient, $m->body);
        $m->update([
            'status' => $status, 'provider' => Gateway::provider($m->channel), 'provider_ref' => $ref, 'error' => $error,
            'attempts' => $m->attempts + 1, 'sent_at' => $status === 'failed' ? null : now(),
        ]);

        return $status;
    }

    /** Try failed messages again (up to three attempts each). */
    public function retryFailed(int $max = 3): int
    {
        $n = 0;
        foreach (OutboundMessage::where('status', 'failed')->where('attempts', '<', $max)->where('created_at', '>=', now()->subDays(2))->limit(200)->get() as $m) {
            $this->deliver($m) !== 'failed' && $n++;
        }

        return $n;
    }
}
