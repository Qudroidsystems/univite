<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MarketPhoto extends Model
{
    protected $fillable = ['market_listing_id', 'path', 'sort_order'];

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
