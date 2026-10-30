<?php

namespace App\Support;

/**
 * Settings → Students (the old portal's Student Profile settings): whether students may update their own profile
 * after verification, and whether the NIN and JAMB number are asked for and shown.
 */
class StudentSettings extends SettingsGroup
{
    protected function prefix(): string
    {
        return 'students';
    }

    public function fields(): array
    {
        return [
            'profile' => ['Student profile', 'heading', null, 'What students see and may change on My profile.'],
            'allow_profile_update' => ['Students may update their contact details, next of kin and referees after they are verified', 'switch', true,
                'Names, date of birth, programme and level never change here; only staff change those.'],
            'show_nin' => ['Ask for and show the National Identity Number (NIN)', 'switch', true, null],
            'require_nin' => ['The NIN is required when students complete their biodata', 'switch', false, null],
            'show_jamb' => ['Show the JAMB registration number on the student\'s profile and slips', 'switch', true, null],
            'referees' => ['Number of referees students give', 'select', '2', null, ['0' => 'None', '1' => 'One', '2' => 'Two']],
        ];
    }
}
