<?php

namespace App\Data\Api;

use App\Services\Messenger;
use Illuminate\Notifications\DatabaseNotification;

/** One notice from the bell, with the app screen it should open. */
final readonly class ApiNotification
{
    public function __construct(
        public string $id, public string $title, public string $body, public string $level, public ?string $topic, public string $screen,
        public ?string $url, public ?string $readAt, public string $createdAt,
    ) {
    }

    public static function from(DatabaseNotification $n): self
    {
        $d = $n->data;
        $topic = $d['topic'] ?? null;

        return new self($n->id, (string) ($d['title'] ?? ''), (string) ($d['body'] ?? ''), (string) ($d['level'] ?? 'info'), $topic,
            Messenger::screen($topic), $d['url'] ?? null, $n->read_at?->toIso8601String(), $n->created_at->toIso8601String());
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'level' => $this->level, 'topic' => $this->topic,
            'screen' => $this->screen, 'url' => $this->url, 'read_at' => $this->readAt, 'created_at' => $this->createdAt,
        ];
    }
}
