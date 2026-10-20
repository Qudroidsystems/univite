<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    protected $fillable = ['student_loan_id', 'amount', 'paid_on', 'channel', 'reference', 'note', 'recorded_by'];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(StudentLoan::class, 'student_loan_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
