<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Something for sale or wanted on the student marketplace, or an item lost or found on campus. */
class MarketListing extends Model
{
    use SoftDeletes;

    public const KINDS = ['sale' => 'For sale', 'wanted' => 'Wanted', 'lost' => 'Lost', 'found' => 'Found'];

    public const CATEGORIES = [
        'books' => 'Books & notes', 'electronics' => 'Phones & electronics', 'computers' => 'Laptops & accessories', 'fashion' => 'Clothes & shoes',
        'hostel' => 'Hostel & room items', 'food' => 'Food & snacks', 'beauty' => 'Beauty & care', 'services' => 'Services', 'tickets' => 'Events & tickets',
        'documents' => 'ID cards & documents', 'keys' => 'Keys', 'bags' => 'Bags & wallets', 'other' => 'Other',
    ];

    public const CONDITIONS = ['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'];

    public const STATUSES = ['pending' => 'Waiting for approval', 'active' => 'Active', 'sold' => 'Sold', 'closed' => 'Closed', 'hidden' => 'Hidden by the school', 'rejected' => 'Not approved'];

    protected $fillable = ['user_id', 'kind', 'title', 'description', 'category', 'price', 'negotiable', 'condition', 'location', 'happened_on',
        'status', 'moderation_note', 'moderated_by', 'views', 'bumped_at'];

    protected $casts = ['price' => 'float', 'negotiable' => 'boolean', 'happened_on' => 'date', 'bumped_at' => 'datetime', 'views' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(MarketPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isLostFound(): bool
    {
        return in_array($this->kind, ['lost', 'found'], true);
    }
}
