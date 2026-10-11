<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A notice shown under the bell in the portal's top bar. */
class PortalNotice extends Notification
{
    public function __construct(private string $title, private string $body, private string $level = 'info', private ?string $url = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'level' => $this->level, 'url' => $this->url];
    }
}
