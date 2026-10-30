<?php

namespace App\Models;

use App\Services\Payments\PaymentSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillItem extends Model
{
    protected $fillable = ['name', 'code', 'description', 'is_compulsory', 'payment_mode', 'max_payments', 'is_active'];

    protected $casts = ['is_compulsory' => 'boolean', 'is_active' => 'boolean', 'max_payments' => 'integer'];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** invoice | separate: the school default for fees that do not choose. */
    public static function defaultPaymentMode(): string
    {
        return app(PaymentSettings::class)->get('default_payment_mode') ?: 'invoice';
    }

    /** invoice | separate: this fee's own choice, or the school default. */
    public function paymentMode(): string
    {
        return $this->payment_mode ?: static::defaultPaymentMode();
    }

    public function paysSeparately(): bool
    {
        return $this->paymentMode() === 'separate';
    }

    /** The most payments allowed, or null for no limit. */
    public function maxPayments(): ?int
    {
        $n = $this->max_payments ?? app(PaymentSettings::class)->int('default_max_payments');

        return $n > 0 ? $n : null;
    }
}
