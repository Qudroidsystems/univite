<?php

namespace App\Support;

/**
 * Settings for the mobile app, edited under Settings → Mobile App and sent to every phone with GET /api/v1/ping
 * (see config()), so switching a feature off here hides it in the app without a new release.
 */
class MobileAppSettings extends SettingsGroup
{
    /** Features the app can show; each can be switched off. */
    public const FEATURES = [
        'chat' => 'Chat (direct messages)',
        'department_groups' => 'Department group chats',
        'course_groups' => 'Course group chats (lecturers and the students taking the course this semester)',
        'study_groups' => 'Study groups students create inside a course',
        'feed' => 'Campus feed',
        'events' => 'Events with RSVP',
        'marketplace' => 'Student marketplace (buy and sell)',
        'lost_found' => 'Lost & found',
        'materials' => 'Course materials',
        'polls' => 'Polls & elections',
        'id_card' => 'Digital ID card',
        'lodging' => 'Students sharing where they live (each student decides)',
        'directory' => 'Find people (students and staff)',
        'gpa_planner' => 'GPA planner',
        'reminders' => 'Class and exam reminders',
    ];

    protected function prefix(): string
    {
        return 'mobile';
    }

    public function fields(): array
    {
        $fields = [
            'connection' => ['Connection', 'heading', null, 'How the app reaches this portal.'],
            'allow_untrusted_certificate' => ['Let the app connect even when the website\'s security certificate cannot be verified', 'switch', false,
                'Use this only while the certificate is being fixed (for example it was issued for another domain, has expired, or the hosting does not send the full certificate chain). '
                . 'Phones then accept this portal\'s current certificate and remember it; a different certificate later is refused. Connections are still encrypted, but the phone cannot prove it is talking to this portal, so switch it off as soon as the certificate is fixed.'],
            'min_app_version' => ['Oldest app version allowed', 'text', null, 'For example 1.2.0. Older apps are asked to update before they can be used. Leave empty to allow every version.', null, ['nullable', 'regex:/^\d+(\.\d+){0,2}$/']],
            'store_url_android' => ['Google Play link', 'text', null, 'Where the update prompt sends Android users.', null, ['nullable', 'url', 'max:255']],
            'store_url_ios' => ['App Store link', 'text', null, null, null, ['nullable', 'url', 'max:255']],

            'features' => ['Features', 'heading', null, 'Switch off anything your school does not want in the app. Changes reach phones the next time the app opens.'],
        ];
        foreach (self::FEATURES as $key => $label) {
            $fields['feature_' . $key] = [$label, 'switch', true, null];
        }

        return $fields + [
            'chat' => ['Chat', 'heading', null, 'Everyone in a department, and everyone taking a course this semester with its lecturers, are put in their group chats automatically.'],
            'students_message_staff' => ['Students may start a direct chat with members of staff', 'switch', true, 'Staff can always message students.'],
            'chat_max_upload_mb' => ['Largest photo or file in chat (MB)', 'number', 8, null, null, ['required', 'integer', 'min:1', 'max:50']],
            'study_group_max' => ['Most members in a study group', 'number', 60, null, null, ['required', 'integer', 'min:2', 'max:1000']],

            'realtime' => ['Live chat (optional)', 'heading', null, 'With a Pusher Channels app, new messages appear instantly. Without it the app checks for new messages every few seconds while a chat is open, and pushes a notification otherwise.'],
            'pusher_app_id' => ['Pusher app ID', 'text', null, null],
            'pusher_key' => ['Pusher key', 'text', null, null],
            'pusher_secret' => ['Pusher secret', 'secret', null, null],
            'pusher_cluster' => ['Pusher cluster', 'text', 'eu', 'For example eu, mt1 or ap2.'],

            'market' => ['Marketplace and lost & found', 'heading', null, 'Students buy and sell among themselves and meet to pay in person; no money passes through the school.'],
            'market_needs_approval' => ['New listings wait for approval before others see them', 'switch', false, 'Approve them under Campus Life → Marketplace.'],
            'market_max_photos' => ['Photos per listing', 'number', 5, null, null, ['required', 'integer', 'min:1', 'max:10']],
            'market_listing_days' => ['Days a listing stays up', 'number', 45, 'After this it is closed unless the seller renews it.', null, ['required', 'integer', 'min:1', 'max:365']],
            'market_rules' => ['Rules shown before posting', 'textarea', "Meet in a busy, public place on campus.\nDo not pay before you see the item.\nNo weapons, drugs, alcohol, exam questions or stolen goods.", null],

            'feed' => ['Campus feed', 'heading', null, 'Staff with "Post to the campus feed" may post to anyone; other staff may post to their own department.'],
            'students_post_feed' => ['Students may post in their department\'s feed', 'switch', false, null],
        ];
    }

    public function feature(string $key): bool
    {
        return $this->on('feature_' . $key);
    }

    public function pusherReady(): bool
    {
        return filled($this->get('pusher_app_id')) && filled($this->get('pusher_key')) && filled($this->get('pusher_secret'));
    }

    /** What the app needs to know, sent in GET /ping (never the secret). */
    public function config(): array
    {
        return [
            'allow_untrusted_certificate' => $this->on('allow_untrusted_certificate'),
            'min_app_version' => $this->get('min_app_version'),
            'store_url_android' => $this->get('store_url_android'),
            'store_url_ios' => $this->get('store_url_ios'),
            'features' => collect(self::FEATURES)->mapWithKeys(fn ($l, $k) => [$k => $this->feature($k)])->all(),
            'pusher' => $this->pusherReady() ? ['key' => $this->get('pusher_key'), 'cluster' => $this->get('pusher_cluster') ?: 'eu'] : null,
            'market' => ['max_photos' => $this->int('market_max_photos'), 'rules' => $this->get('market_rules'), 'needs_approval' => $this->on('market_needs_approval')],
            'chat' => ['max_upload_mb' => $this->int('chat_max_upload_mb')],
        ];
    }
}
