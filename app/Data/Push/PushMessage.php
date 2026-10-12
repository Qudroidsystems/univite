<?php

namespace App\Data\Push;

/**
 * One push notification for the mobile app. $data reaches the app untouched (all values are strings, as Firebase
 * requires): topic, the portal link, the screen to open and the notification id.
 */
final readonly class PushMessage
{
    /** @param  array<string, string>  $data */
    public function __construct(public string $title, public string $body, public array $data = [])
    {
    }
}
