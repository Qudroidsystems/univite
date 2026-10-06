<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillItem extends Model
{
    protected $fillable = ['name', 'code', 'description', 'is_compulsory', 'is_active'];

    protected $casts = ['is_compulsory' => 'boolean', 'is_active' => 'boolean'];

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
