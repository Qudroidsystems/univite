<?php

namespace App\Services;

use App\Data\Push\PushMessage;
use App\Messaging\Gateway;
use App\Messaging\PushGateway;
use App\Models\DeviceToken;
use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PortalNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place notices go out from: a portal notice under the bell (also listed in the mobile app), a push
 * notification to each phone signed in to the mobile app, plus SMS and WhatsApp when switched on for the topic and
 * the person has a number and has not opted out. Every SMS/WhatsApp/push is logged in outbound_messages; a dedupe key
 * stops the same reminder going twice, and failed messages are retried by `messages:retry`. Pushes are queued and
 * sent by `messages:push` every minute, so publishing results to a whole class does not hold up the page.
 * Emails with documents (letters, slips) go through DocumentMailer.
 */
class Messenger
{
    public const TOPICS = [
        'results' => 'Results published',
        'registration' => 'Course registration decisions',
        'fees' => 'Invoices, payments and receipts',
        'clearance' => 'Clearance and matric numbers',
        'announcements' => 'General announcements',
        'leave' => 'Leave applications, decisions and reminders',
        'leave_approvals' => 'Leave waiting for an approver, and escalations',
        'timetable' => 'Published timetables and changes',
        'seating' => 'Examination seat numbers',
        'venues' => 'Venue closures that affect timetables',
        'payslip' => 'Payslips ready',
        'id_cards' => 'Identity cards ready and collected',
        'library' => 'Library books due, overdue and ready to collect, and library charges',
        'health' => 'Health centre notices',
        'accommodation' => 'Hostel allocations, payment deadlines and check-in',
        'teaching_practice' => 'Teaching practice postings, supervisors and scores',
        'chat' => 'Chat messages (mobile app)',
        'marketplace' => 'Marketplace and lost & found (mobile app)',
        'feed' => 'Campus feed, events and comments (mobile app)',
        'materials' => 'New course materials (mobile app)',
    ];

    /** The mobile app screen a notice on each topic opens. */
    public const SCREENS = [
        'results' => 'results', 'registration' => 'courses', 'fees' => 'fees', 'clearance' => 'clearance', 'timetable' => 'timetable',
        'seating' => 'exams', 'venues' => 'timetable', 'library' => 'library', 'accommodation' => 'accommodation', 'id_cards' => 'id_card', 'payslip' => 'payslips',
        'leave' => 'leave', 'leave_approvals' => 'leave', 'teaching_practice' => 'teaching_practice', 'health' => 'health',
        'chat' => 'chat', 'marketplace' => 'marketplace', 'feed' => 'feed', 'materials' => 'materials',
    ];

    public const CHANNELS = ['portal', 'push', 'sms', 'whatsapp'];

    public function __construct(private Gateway $gateway, private PushGateway $push)
    {
    }

    /** Push is on for every topic unless switched off; SMS only for leave; WhatsApp off. */
    public static function channelOn(string $topic, string $channel): bool
    {
        $default = match ($channel) {
            'push' => '1',
            'sms' => str_starts_with($topic, 'leave') ? '1' : '0',
            default => '0',
        };

        return Setting::get("messaging.topic.{$topic}.{$channel}", $default) === '1';
    }

    public static function screen(?string $topic): string
    {
        return self::SCREENS[$topic] ?? 'notifications';
    }

    /**
     * @param  array<int, string>|null  $channels  defaults to every channel switched on for the topic
     * @return array<string, string> channel => status
     */
    public function notify(User $user, string $topic, string $title, string $body, ?Model $subject = null, ?string $url = null, string $level = 'info', ?string $dedupe = null, ?array $channels = null): array
    {
        $out = [];
        $channels ??= self::CHANNELS;

        DB::afterCommit(function () use ($user, $topic, $title, $body, $subject, $url, $level, $dedupe, $channels, &$out) {
            $noticeId = null;
            if (in_array('portal', $channels, true)) {
                $notice = new PortalNotice($title, $body, $level, $url, $topic);
                $notice->id = (string) Str::uuid();   // known up front so the push can point at it
                $user->notify($notice);
                $noticeId = $notice->id;
            }
            if (in_array('push', $channels, true) && self::channelOn($topic, 'push') && ($user->notify_push ?? true)) {
                $out['push'] = $this->queuePush($user, $topic, $title, $body, $subject, $url, $noticeId, $dedupe);
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

    /**
     * A push only, with no notice under the bell (chat messages, likes ...). $data is merged into the push's data, for
     * example ['conversation_id' => '12'] so the app opens that chat. Respects the topic's push switch and the person's choice.
     */
    public function pushOnly(User $user, string $topic, string $title, string $body, array $data = [], ?string $dedupe = null): string
    {
        if (! self::channelOn($topic, 'push') || ! ($user->notify_push ?? true)) {
            return 'off';
        }
        $devices = $user->deviceTokens()->get();
        if ($devices->isEmpty()) {
            return 'none';
        }
        $payload = json_encode([
            'title' => Str::limit($title, 120, '…'), 'body' => Str::limit($body, 300, '…'),
            'data' => array_map('strval', array_filter(['topic' => $topic, 'screen' => self::screen($topic)] + $data, fn ($v) => $v !== null)),
        ]);
        foreach ($devices as $device) {
            $key = $dedupe ? "{$dedupe}:push:{$device->id}" : null;
            OutboundMessage::create([
                'user_id' => $user->id, 'channel' => 'push', 'recipient' => 'device:' . $device->id, 'body' => $payload, 'topic' => $topic,
                'dedupe_key' => $key, 'status' => 'queued',
            ]);
        }

        return 'queued';
    }

    /** One queued push per phone the person is signed in on. Returns "queued" or "none" (no phones). */
    private function queuePush(User $user, string $topic, string $title, string $body, ?Model $subject, ?string $url, ?string $noticeId, ?string $dedupe): string
    {
        $devices = $user->deviceTokens()->get();
        if ($devices->isEmpty()) {
            return 'none';
        }
        $payload = json_encode([
            'title' => Str::limit($title, 120, '…'), 'body' => Str::limit($body, 500, '…'),
            'data' => array_filter(['topic' => $topic, 'screen' => self::screen($topic), 'url' => $url, 'notification_id' => $noticeId,
                'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject ? (string) $subject->getKey() : null]),
        ]);
        foreach ($devices as $device) {
            $key = $dedupe ? "{$dedupe}:push:{$device->id}" : null;
            if ($key && OutboundMessage::where('dedupe_key', $key)->exists()) {
                continue;
            }
            OutboundMessage::create([
                'user_id' => $user->id, 'channel' => 'push', 'recipient' => 'device:' . $device->id, 'body' => $payload, 'topic' => $topic,
                'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(), 'dedupe_key' => $key, 'status' => 'queued',
            ]);
        }

        return 'queued';
    }

    /** Send queued pushes (run every minute by the scheduler). */
    public function sendQueuedPushes(int $limit = 500): int
    {
        $n = 0;
        foreach (OutboundMessage::where('channel', 'push')->where('status', 'queued')->orderBy('id')->limit($limit)->get() as $m) {
            $this->deliver($m) === 'sent' && $n++;
        }

        return $n;
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
        if ($m->channel === 'push') {
            return $this->deliverPush($m);
        }
        [$status, $ref, $error] = $this->gateway->send($m->channel, $m->recipient, $m->body);
        $m->update([
            'status' => $status, 'provider' => Gateway::provider($m->channel), 'provider_ref' => $ref, 'error' => $error,
            'attempts' => $m->attempts + 1, 'sent_at' => $status === 'failed' ? null : now(),
        ]);

        return $status;
    }

    private function deliverPush(OutboundMessage $m): string
    {
        $device = DeviceToken::find((int) str_replace('device:', '', $m->recipient));
        $p = json_decode($m->body, true) ?: [];
        if (! $device) {
            $m->update(['status' => 'failed', 'error' => 'The phone has signed out of the app.', 'attempts' => 99]);

            return 'failed';
        }
        $result = $this->push->send($device, new PushMessage((string) ($p['title'] ?? ''), (string) ($p['body'] ?? ''), $p['data'] ?? []));
        if ($result->status === 'invalid') {
            $device->delete();   // the app was removed from that phone
        }
        $status = $result->status === 'invalid' ? 'failed' : $result->status;
        $m->update([
            'status' => $status, 'provider' => Gateway::provider('push'), 'provider_ref' => $result->reference, 'error' => $result->error,
            'attempts' => $result->status === 'invalid' ? 99 : $m->attempts + 1, 'sent_at' => $status === 'failed' ? null : now(),
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
