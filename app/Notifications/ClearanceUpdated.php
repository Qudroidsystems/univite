<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails a student that a clearance item was cleared or rejected, or that they are fully cleared (the bell and the
 * mobile app get the notice through Messenger). */
class ClearanceUpdated extends Notification
{
    public function __construct(private string $title, private string $body, private string $level = 'info')
    {
    }

    public function via(object $notifiable): array
    {
        $mailable = ! in_array(config('mail.default'), ['log', 'array'], true) && $notifiable->email;

        return $mailable ? ['mail'] : [];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'level' => $this->level];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->body)->action('Open my clearance', route('my.clearance'));
    }
}
