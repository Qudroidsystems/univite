<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayslipLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['payslip_id', 'kind', 'code', 'name', 'amount', 'staff_pay_item_id', 'sort_order'];

    protected $casts = ['amount' => 'float'];
}
