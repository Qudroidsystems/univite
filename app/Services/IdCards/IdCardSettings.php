<?php

namespace App\Services\IdCards;

use App\Support\Institution;
use App\Support\SettingsGroup;

/** How identity cards look and how long they last. */
class IdCardSettings extends SettingsGroup
{
    protected function prefix(): string
    {
        return 'idcards';
    }

    public function fields(): array
    {
        return [
            'serial_prefix' => ['Serial number prefix', 'text', '', 'Printed before the card number, for example AFUED. The institution\'s short name is used when this is empty.'],
            'colour' => ['Card colour', 'color', '', 'The band across the top of the card. The portal\'s main colour is used when this is not set.'],
            'student_validity' => ['Student cards are valid', 'select', 'course', null, ['course' => 'Until the expected end of the student\'s programme', 'months' => 'For a fixed number of months']],
            'student_months' => ['Months a student card lasts (when fixed)', 'number', 12, null],
            'staff_months' => ['Months a staff card lasts', 'number', 60, null],
            'signatory_title' => ['Signed by (title)', 'text', 'Registrar', 'Printed under the signature. The Registrar\'s signature from the institution profile is used.'],
            'signatory_name' => ['Signed by (name)', 'text', '', 'Leave empty to use the Registrar\'s name from the institution profile.'],
            'back_text' => ['Text on the back', 'textarea', 'This card is the property of the institution and must be shown on request. It is not transferable. If found, please return it to the nearest security post or the Registry.', null],
            'show_blood_group' => ['Print the holder\'s blood group and genotype when recorded by the clinic', 'switch', false, null],
            'replacement_fee' => ['Fee for a replacement card (₦)', 'number', 0, 'Charged to students who ask for a replacement because a card was lost or damaged. 0 means replacements are free. Staff pay at the desk.'],
            'replacement_item_id' => ['Bill item the replacement fee is recorded under', 'bill_item', null, null],
        ];
    }

    public function colour(): string
    {
        return $this->get('colour') ?: (Institution::get('primary_color') ?: '#405189');
    }

    public function prefixText(): string
    {
        return strtoupper($this->get('serial_prefix') ?: (Institution::get('short_name') ?: 'ID'));
    }

    public function signatoryName(): ?string
    {
        return $this->get('signatory_name') ?: Institution::get('registrar_name');
    }
}
