<?php

namespace App\Http\Controllers;

use App\Messaging\Gateway;
use App\Services\Messenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Everyone's own notices (the bell) and how they want to be reached by text message and WhatsApp. */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', ['pagetitle' => 'Notifications', 'items' => $request->user()->notifications()->latest()->paginate(30)]);
    }

    public function open(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->findOrFail($id);
        $n->markAsRead();
        $url = $n->data['url'] ?? null;

        // Only follow links into this portal.
        return $url && str_starts_with($url, url('/')) ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    /**
     * What the bell needs to stay current without a page reload: the unread count and the notices that arrived after
     * the time the page last saw (the page asks every half minute while it is open, and when it comes back into view).
     */
    public function live(Request $request): JsonResponse
    {
        $user = $request->user();
        $after = rescue(fn () => \Illuminate\Support\Carbon::parse((string) $request->query('after')), null, false);
        $new = $after ? $user->unreadNotifications()->where('created_at', '>', $after)->latest()->limit(10)->get() : collect();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'now' => now()->toIso8601String(),
            'items' => $new->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->data['title'] ?? 'Notice', 'body' => \Illuminate\Support\Str::limit($n->data['body'] ?? '', 140),
                'topic' => $n->data['topic'] ?? null, 'level' => $n->data['level'] ?? 'info',
                'url' => $n->data['url'] ?? null, 'open' => route('notifications.open', $n->id), 'ago' => $n->created_at->diffForHumans(),
            ])->values(),
        ]);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }

    public function preferences(Request $request): View
    {
        $sms = collect(Messenger::TOPICS)->filter(fn ($l, $t) => Messenger::channelOn($t, 'sms'));
        $wa = collect(Messenger::TOPICS)->filter(fn ($l, $t) => Messenger::channelOn($t, 'whatsapp'));

        return view('notifications.preferences', ['pagetitle' => 'Notification settings', 'u' => $request->user(), 'sms' => $sms, 'whatsapp' => $wa, 'devices' => $request->user()->deviceTokens()->count()]);
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]{7,30}$/'], 'whatsapp_number' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]{7,30}$/'],
            'notify_sms' => ['nullable', 'boolean'], 'notify_whatsapp' => ['nullable', 'boolean'], 'notify_push' => ['nullable', 'boolean'],
        ], ['regex' => 'Enter a phone number using digits only (a leading + is fine).']);
        $request->user()->update(['phone_number' => $d['phone_number'] ?? null, 'whatsapp_number' => $d['whatsapp_number'] ?? null, 'notify_sms' => $request->boolean('notify_sms'), 'notify_whatsapp' => $request->boolean('notify_whatsapp'), 'notify_push' => $request->boolean('notify_push')]);

        return back()->with('success', 'Saved.' . (($d['phone_number'] ?? null) && ! Gateway::normalise($d['phone_number']) ? ' That phone number does not look complete.' : ''));
    }
}
