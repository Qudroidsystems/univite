<?php

namespace App\Http\Controllers;

use App\Messaging\Gateway;
use App\Services\Messenger;
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

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }

    public function preferences(Request $request): View
    {
        $sms = collect(Messenger::TOPICS)->filter(fn ($l, $t) => Messenger::channelOn($t, 'sms'));
        $wa = collect(Messenger::TOPICS)->filter(fn ($l, $t) => Messenger::channelOn($t, 'whatsapp'));

        return view('notifications.preferences', ['pagetitle' => 'Notification settings', 'u' => $request->user(), 'sms' => $sms, 'whatsapp' => $wa]);
    }

    public function savePreferences(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]{7,30}$/'], 'whatsapp_number' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9 ()-]{7,30}$/'],
            'notify_sms' => ['nullable', 'boolean'], 'notify_whatsapp' => ['nullable', 'boolean'],
        ], ['regex' => 'Enter a phone number using digits only (a leading + is fine).']);
        $request->user()->update(['phone_number' => $d['phone_number'] ?? null, 'whatsapp_number' => $d['whatsapp_number'] ?? null, 'notify_sms' => $request->boolean('notify_sms'), 'notify_whatsapp' => $request->boolean('notify_whatsapp')]);

        return back()->with('success', 'Saved.' . (($d['phone_number'] ?? null) && ! Gateway::normalise($d['phone_number']) ? ' That phone number does not look complete.' : ''));
    }
}
