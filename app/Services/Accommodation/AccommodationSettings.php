<?php

namespace App\Services\Accommodation;

use App\Support\SettingsGroup;

/** How students apply for hostel places and how beds are shared out. */
class AccommodationSettings extends SettingsGroup
{
    public const PRIORITIES = [
        'applied' => 'In the order students applied',
        'new_first' => 'New (first-year) students first, then in the order they applied',
        'final_first' => 'Final-year students first, then in the order they applied',
        'ballot' => 'By ballot (a random draw)',
    ];

    protected function prefix(): string
    {
        return 'accommodation';
    }

    public function fields(): array
    {
        return [
            'applications_open' => ['Students can apply for accommodation now', 'switch', false, null],
            'max_choices' => ['Hostels a student may list in order of preference', 'number', 3, null],
            'priority' => ['When there are more applicants than beds, place students', 'select', 'applied', null, self::PRIORITIES],
            'any_hostel' => ['If none of a student\'s choices has space, place them in any hostel open to them', 'switch', true, null],
            'payment_days' => ['Days a student has to pay before the bed is released to someone else', 'number', 7, null],
            'bill_item_id' => ['Bill item accommodation fees are recorded under', 'bill_item', null, null],
            'instructions' => ['Notes shown to students on the accommodation page', 'textarea', '', null],
        ];
    }
}
