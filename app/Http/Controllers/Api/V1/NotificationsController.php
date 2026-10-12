<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiNotification;
use App\Messaging\Gateway;
use App\Services\Messenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The same notices as the bell in the portal, and how the person wants to be reached. */
class NotificationsController extends ApiController
{
    /** GET /api/v1/notifications?unread=1&page=2 */
    public function index(Request $request): JsonResponse
    {
        $q = $request->boolean('unread') ? $request->user()->unreadNotifications() : $request->user()->notifications();
        $page = $q->latest()->paginate(min(50, max(5, (int) $request->query('per_page', 20))));

        return $this->ok(collect($page->items())->map(fn ($n) => ApiNotification::from($n)->toArray())->all(), [
            'unread' => $request->user()->unreadNotifications()->count(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $n = $request->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return $this->ok(ApiNotification::from($n)->toArray(), ['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok(['unread' => 0]);
    }

    /** GET /api/v1/notifications/settings: the person's own choices, and which topics the school sends by each channel. */
    public function settings(Request $request): JsonResponse
    {
        return $this->ok($this->preferences($request->user()));
    }

    private function preferences(\App\Models\User $u): array
    {
        return [
            'push' => (bool) ($u->notify_push ?? true), 'sms' => (bool) ($u->notify_sms ?? true), 'whatsapp' => (bool) ($u->notify_whatsapp ?? true),
            'phone_number' => $u->phone_number, 'whatsapp_number' => $u->whatsapp_number,
            'topics' => collect(Messenger::TOPICS)->map(fn ($label, $t) => ['topic' => $t, 'label' => $label, 'push' => Messenger::channelOn($t, 'push'),
                'sms' => Messenger::channelOn($t, 'sms'), 'whatsapp' => Messenger::channelOn($t, 'whatsapp')])->values()->all(),
        ];
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'push' => ['sometimes', 'boolean'], 'sms' => ['sometimes', 'boolean'], 'whatsapp' => ['sometimes', 'boolean'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]{7,30}$/'], 'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]{7,30}$/'],
        ], ['regex' => 'Enter a phone number using digits only (a leading + is fine).']);
        $map = ['push' => 'notify_push', 'sms' => 'notify_sms', 'whatsapp' => 'notify_whatsapp', 'phone_number' => 'phone_number', 'whatsapp_number' => 'whatsapp_number'];
        $request->user()->update(collect($d)->mapWithKeys(fn ($v, $k) => [$map[$k] => $v])->all());

        $warning = ($d['phone_number'] ?? null) && ! Gateway::normalise($d['phone_number']) ? 'That phone number does not look complete.' : null;

        return $this->ok($this->preferences($request->user()->fresh()), $warning ? ['warning' => $warning] : []);
    }
}
