<?php

namespace App\Services\Library;

use App\Support\SettingsGroup;

/** Borrowing rules: how long, how many, renewals, holds and overdue fines. */
class LibrarySettings extends SettingsGroup
{
    protected function prefix(): string
    {
        return 'library';
    }

    public function fields(): array
    {
        return [
            'student_days' => ['Days a student may keep a book', 'number', 14, null],
            'student_limit' => ['Books a student may have at once', 'number', 3, null],
            'staff_days' => ['Days a member of staff may keep a book', 'number', 30, null],
            'staff_limit' => ['Books a member of staff may have at once', 'number', 10, null],
            'max_renewals' => ['Times a loan may be renewed', 'number', 2, 'A loan cannot be renewed while someone is waiting for the title.'],
            'self_renew' => ['Borrowers may renew their own loans on the portal', 'switch', true, null],
            'hold_days' => ['Days a returned copy is kept for the person waiting', 'number', 3, null],
            'fine_per_day' => ['Overdue fine per day (₦)', 'number', 0, '0 means no overdue fines.'],
            'max_fine' => ['Most a single overdue fine can reach (₦)', 'number', 0, '0 means no cap.'],
            'grace_days' => ['Days late before a fine starts', 'number', 0, null],
            'block_on_fines' => ['Stop borrowing while a fine is unpaid', 'switch', true, null],
            'fine_item_id' => ['Bill item fines and lost-book charges are recorded under', 'bill_item', null, 'Students\' fines are added to My Fees as an invoice. Staff pay at the desk.'],
            'remind_days' => ['Remind borrowers this many days before a book is due', 'number', 2, null],
            'accession_prefix' => ['Accession number prefix', 'text', 'LIB', 'New copies are numbered with this prefix unless you type the numbers yourself.'],
        ];
    }
}
