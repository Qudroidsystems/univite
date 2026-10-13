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
        'reading_planner' => 'Reading planner (weekly study timetable with reminders)',
        'study_companion' => 'AI study companion (practice questions and answers from course materials)',
        'campus_map' => 'Distance and walking directions to lecture and exam venues',
        'health_reminders' => 'Medication and clinic appointment reminders (kept only on the student\'s phone)',
    ];

    /** AI services the study companion can use; the school brings its own key. */
    public const AI_PROVIDERS = ['none' => 'Off', 'anthropic' => 'Anthropic Claude', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini'];

    public const AI_DEFAULT_MODELS = ['anthropic' => 'claude-sonnet-5-5', 'openai' => 'gpt-4o-mini', 'gemini' => 'gemini-2.5-flash'];

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

            'study' => ['Academic companion', 'heading', null, 'The GPA planner, reading planner and reminders work without anything else. The AI tutor needs an account with one of the AI services below; the key stays on this server and is never sent to phones.'],
            'show_running_ca' => ['Students see their CA marks in the GPA planner before results are published', 'switch', false,
                'The planner can then work out the exam score each student needs for a grade. Leave off if continuous assessment marks must stay private until approval.'],
            'ai_provider' => ['AI service for the study companion', 'select', 'none', null, self::AI_PROVIDERS, ['nullable', 'in:' . implode(',', array_keys(self::AI_PROVIDERS))]],
            'ai_model' => ['AI model', 'text', null, 'Leave empty for the default: ' . implode(', ', array_map(fn ($p, $m) => self::AI_PROVIDERS[$p] . ' ' . $m, array_keys(self::AI_DEFAULT_MODELS), self::AI_DEFAULT_MODELS)) . '.', null, ['nullable', 'string', 'max:100']],
            'ai_api_key' => ['AI service API key', 'secret', null, 'From console.anthropic.com, platform.openai.com or aistudio.google.com.'],
            'ai_daily_limit' => ['AI requests per student per day', 'number', 40, 'Each question asked, quiz made or answer marked counts as one. Protects the school\'s AI bill.', null, ['nullable', 'integer', 'min:1', 'max:1000']],
            'study_upload_mb' => ['Largest study document a student may upload (MB)', 'number', 15, null, null, ['nullable', 'integer', 'min:1', 'max:50']],

            'health' => ['Health centre', 'heading', null, 'Shown with the app\'s medication and appointment reminders, which stay on the student\'s phone and are never sent to the portal.'],
            'health_centre_name' => ['Health centre name', 'text', 'University Health Centre', null],
            'health_centre_phone' => ['Health centre phone (emergencies)', 'text', null, null],
            'health_centre_latitude' => ['Health centre latitude', 'text', null, 'For directions in the app, e.g. 7.8231450.', null, ['nullable', 'numeric', 'between:-90,90']],
            'health_centre_longitude' => ['Health centre longitude', 'text', null, null, null, ['nullable', 'numeric', 'between:-180,180']],
        ];
    }

    public function feature(string $key): bool
    {
        return $this->on('feature_' . $key);
    }

    public function aiProvider(): ?string
    {
        $p = $this->get('ai_provider');

        return ($p && $p !== 'none' && isset(self::AI_DEFAULT_MODELS[$p]) && filled($this->get('ai_api_key'))) ? $p : null;
    }

    public function aiModel(): ?string
    {
        $p = $this->aiProvider();

        return $p ? (trim((string) $this->get('ai_model')) ?: self::AI_DEFAULT_MODELS[$p]) : null;
    }

    public function healthCentre(): array
    {
        $lat = $this->get('health_centre_latitude');
        $lng = $this->get('health_centre_longitude');

        return ['name' => $this->get('health_centre_name') ?: 'Health centre', 'phone' => $this->get('health_centre_phone'),
            'lat' => is_numeric($lat) ? (float) $lat : null, 'lng' => is_numeric($lng) ? (float) $lng : null];
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
            'study' => ['ai' => $this->feature('study_companion') && $this->aiProvider() !== null, 'running_ca' => $this->on('show_running_ca'),
                'daily_limit' => $this->int('ai_daily_limit'), 'upload_mb' => $this->int('study_upload_mb')],
            'health_centre' => $this->healthCentre(),
        ];
    }
}
