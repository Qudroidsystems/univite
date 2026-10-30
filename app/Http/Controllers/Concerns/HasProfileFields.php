<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Validation\Rule;

/** Personal, origin and guardian fields shared by the applicant and student forms. */
trait HasProfileFields
{
    protected function states(): array
    {
        return \App\Support\Lookups::options('state');
    }

    protected function profileRules(): array
    {
        return [
            'first_name'            => ['required', 'string', 'max:100'],
            'last_name'             => ['required', 'string', 'max:100'],
            'other_names'           => ['nullable', 'string', 'max:100'],
            'phone_number'          => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'gender'                => ['nullable', Rule::in(array_keys(\App\Support\Lookups::options('gender')))],
            'date_of_birth'         => ['nullable', 'date', 'after:1930-01-01', 'before:today'],
            'state_of_origin'       => ['nullable', 'string', 'max:100'],
            'lga'                   => ['nullable', 'string', 'max:100'],
            'nationality'           => ['nullable', 'string', 'max:100'],
            'address'               => ['nullable', 'string', 'max:500'],
            'guardian_name'         => ['nullable', 'string', 'max:150'],
            'guardian_relationship' => ['nullable', 'string', 'max:50'],
            'guardian_phone'        => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'guardian_address'      => ['nullable', 'string', 'max:500'],
            'nin'                   => ['nullable', 'digits:11'],
            'religion'              => ['nullable', Rule::in(array_keys(\App\Support\Lookups::options('religion')))],
            'blood_group'           => ['nullable', Rule::in(array_keys(\App\Support\Lookups::options('blood_group')))],
            'genotype'              => ['nullable', Rule::in(array_keys(\App\Support\Lookups::options('genotype')))],
            'marital_status'        => ['nullable', Rule::in(array_keys(\App\Support\Lookups::options('marital_status')))],
            'next_of_kin_email'     => ['nullable', 'email', 'max:150'],
        ];
    }

    /** @return array{0: array, 1: array} [bio fields, guardian fields] for the modal form */
    protected function profileFieldGroups(): array
    {
        $bio = [
            ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'col' => 4],
            ['name' => 'last_name', 'label' => 'Surname', 'type' => 'text', 'required' => true, 'col' => 4],
            ['name' => 'other_names', 'label' => 'Other names', 'type' => 'text', 'col' => 4],
        ];

        $details = [
            ['name' => 'phone_number', 'label' => 'Phone number', 'type' => 'text', 'col' => 4],
            ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'options' => \App\Support\Lookups::options('gender'), 'col' => 4],
            ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'col' => 4],
            ['name' => 'nationality', 'label' => 'Nationality', 'type' => 'text', 'col' => 4, 'default' => 'Nigerian'],
            ['name' => 'state_of_origin', 'label' => 'State of origin', 'type' => 'select', 'options' => $this->states(), 'col' => 4],
            ['name' => 'lga', 'label' => 'Local government area', 'type' => 'text', 'col' => 4],
            ['name' => 'nin', 'label' => 'National Identity Number (NIN)', 'type' => 'text', 'col' => 4, 'placeholder' => '11 digits'],
            ['name' => 'religion', 'label' => 'Religion', 'type' => 'select', 'options' => \App\Support\Lookups::options('religion'), 'blank' => 'Not given', 'col' => 4],
            ['name' => 'marital_status', 'label' => 'Marital status', 'type' => 'select', 'options' => \App\Support\Lookups::options('marital_status'), 'blank' => 'Not given', 'col' => 4],
            ['name' => 'blood_group', 'label' => 'Blood group', 'type' => 'select', 'options' => \App\Support\Lookups::options('blood_group'), 'blank' => 'Not known', 'col' => 4],
            ['name' => 'genotype', 'label' => 'Genotype', 'type' => 'select', 'options' => \App\Support\Lookups::options('genotype'), 'blank' => 'Not known', 'col' => 4],
            ['name' => 'address', 'label' => 'Home address', 'type' => 'textarea', 'col' => 12],
        ];

        $guardian = [
            ['name' => 'guardian_name', 'label' => 'Guardian / next of kin', 'type' => 'text', 'col' => 6],
            ['name' => 'guardian_relationship', 'label' => 'Relationship', 'type' => 'text', 'col' => 6, 'placeholder' => 'Father, mother, uncle ...'],
            ['name' => 'guardian_phone', 'label' => 'Guardian phone', 'type' => 'text', 'col' => 6],
            ['name' => 'next_of_kin_email', 'label' => 'Guardian email', 'type' => 'email', 'col' => 6],
            ['name' => 'guardian_address', 'label' => 'Guardian address', 'type' => 'textarea', 'col' => 12],
        ];

        return [$bio, $details, $guardian];
    }
}
