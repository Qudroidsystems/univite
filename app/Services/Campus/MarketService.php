<?php

namespace App\Services\Campus;

use App\Models\MarketListing;
use App\Models\MarketPhoto;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Messenger;
use App\Support\MobileAppSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The student marketplace (for sale, wanted) and lost & found. Buyers and sellers meet and pay in person; the school
 * only hosts the listings. Listings can wait for approval (Settings → Mobile App), expire after a set number of days
 * unless renewed, and are hidden by moderators on reports.
 */
class MarketService
{
    public function __construct(private MobileAppSettings $settings, private ChatService $chat, private Messenger $messenger)
    {
    }

    private function enabledFor(string $kind): void
    {
        $feature = in_array($kind, ['lost', 'found'], true) ? 'lost_found' : 'marketplace';
        if (! $this->settings->feature($feature)) {
            throw new RuntimeException($feature === 'lost_found' ? 'Lost & found is switched off.' : 'The marketplace is switched off.');
        }
    }

    /** Close listings older than the set number of days (run daily). */
    public function expire(): int
    {
        $days = max(1, $this->settings->int('market_listing_days'));

        return MarketListing::where('status', 'active')->where('bumped_at', '<', now()->subDays($days))->update(['status' => 'closed']);
    }

    /** Listings others can see: active, not from people blocked either way. */
    public function browse(User $viewer, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $kinds = match ($filters['section'] ?? 'market') {
            'lost_found' => ['lost', 'found'],
            default => ['sale', 'wanted'],
        };
        $q = trim((string) ($filters['q'] ?? ''));

        return MarketListing::with(['photos', 'user.student.programme'])
            ->where('status', 'active')
            ->whereIn('kind', ! empty($filters['kind']) && in_array($filters['kind'], $kinds, true) ? [$filters['kind']] : $kinds)
            ->whereNotIn('user_id', UserBlock::hiddenFor($viewer->id))
            ->when(! empty($filters['category']), fn ($w) => $w->where('category', $filters['category']))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('title', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")))
            ->when(isset($filters['min_price']) && is_numeric($filters['min_price']), fn ($w) => $w->where('price', '>=', (float) $filters['min_price']))
            ->when(isset($filters['max_price']) && is_numeric($filters['max_price']), fn ($w) => $w->where('price', '<=', (float) $filters['max_price']))
            ->when(($filters['sort'] ?? '') === 'price_asc', fn ($w) => $w->orderBy('price'), fn ($w) => $w->when(($filters['sort'] ?? '') === 'price_desc', fn ($x) => $x->orderByDesc('price'), fn ($x) => $x->orderByDesc('bumped_at')))
            ->paginate(min(50, max(5, $perPage)));
    }

    public function mine(User $user)
    {
        return MarketListing::with('photos')->where('user_id', $user->id)->latest('bumped_at')->get();
    }

    public function saved(User $user)
    {
        return MarketListing::with(['photos', 'user'])->whereIn('id', DB::table('market_saves')->where('user_id', $user->id)->pluck('market_listing_id'))
            ->where('status', 'active')->latest('bumped_at')->get();
    }

    /** A listing for one person to look at: their own in any state, others' only when active. */
    public function viewable(MarketListing $listing, User $viewer): bool
    {
        if ($listing->user_id === $viewer->id || $viewer->can('Moderate the marketplace')) {
            return true;
        }

        return in_array($listing->status, ['active', 'sold'], true) && ! in_array($listing->user_id, UserBlock::hiddenFor($viewer->id), true);
    }

    /**
     * @param  UploadedFile[]  $photos
     */
    public function create(User $user, array $data, array $photos = []): MarketListing
    {
        $this->enabledFor($data['kind']);
        $this->checkPhotos($photos, 0);
        if (MarketListing::where('user_id', $user->id)->where('created_at', '>', now()->subDay())->count() >= 20) {
            throw new RuntimeException('You have posted a lot today. Try again tomorrow.');
        }

        return DB::transaction(function () use ($user, $data, $photos) {
            $listing = MarketListing::create($this->fields($data) + [
                'user_id' => $user->id, 'kind' => $data['kind'],
                'status' => $this->settings->on('market_needs_approval') ? 'pending' : 'active',
                'bumped_at' => now(),
            ]);
            $this->addPhotos($listing, $photos);

            return $listing->load('photos');
        });
    }

    public function update(MarketListing $listing, User $user, array $data, array $newPhotos = [], array $removePhotoIds = []): MarketListing
    {
        $this->own($listing, $user);
        if (in_array($listing->status, ['hidden'], true)) {
            throw new RuntimeException('This listing was hidden by the school and cannot be changed.');
        }

        return DB::transaction(function () use ($listing, $data, $newPhotos, $removePhotoIds) {
            foreach ($listing->photos()->whereIn('id', $removePhotoIds)->get() as $p) {
                Storage::disk('public')->delete($p->path);
                $p->delete();
            }
            $this->checkPhotos($newPhotos, $listing->photos()->count());
            $listing->update($this->fields($data + ['kind' => $listing->kind]) + ($listing->status === 'rejected' ? ['status' => 'pending'] : []));
            $this->addPhotos($listing, $newPhotos);

            return $listing->fresh('photos');
        });
    }

    private function fields(array $d): array
    {
        $price = isset($d['price']) && $d['price'] !== '' && $d['price'] !== null ? round((float) $d['price'], 2) : null;
        if ($price !== null && ($price < 0 || $price > 50_000_000)) {
            throw new RuntimeException('Enter a sensible price.');
        }

        return [
            'title' => mb_substr(trim((string) $d['title']), 0, 120),
            'description' => isset($d['description']) ? mb_substr(trim((string) $d['description']), 0, 3000) : null,
            'category' => isset($d['category']) && isset(MarketListing::CATEGORIES[$d['category']]) ? $d['category'] : 'other',
            'price' => in_array($d['kind'] ?? 'sale', ['lost', 'found'], true) ? null : $price,
            'negotiable' => ! empty($d['negotiable']),
            'condition' => isset($d['condition']) && isset(MarketListing::CONDITIONS[$d['condition']]) ? $d['condition'] : null,
            'location' => isset($d['location']) ? mb_substr(trim((string) $d['location']), 0, 150) : null,
            'happened_on' => $d['happened_on'] ?? null,
        ];
    }

    private function checkPhotos(array $photos, int $existing): void
    {
        $max = $this->settings->int('market_max_photos');
        if ($existing + count($photos) > $max) {
            throw new RuntimeException("A listing can have up to {$max} photos.");
        }
        foreach ($photos as $p) {
            if (! $p instanceof UploadedFile || ! str_starts_with((string) $p->getMimeType(), 'image/') || $p->getSize() > 6 * 1024 * 1024) {
                throw new RuntimeException('Photos must be pictures of up to 6 MB.');
            }
        }
    }

    private function addPhotos(MarketListing $listing, array $photos): void
    {
        $n = (int) $listing->photos()->max('sort_order');
        foreach ($photos as $p) {
            MarketPhoto::create(['market_listing_id' => $listing->id, 'path' => $p->store('market/' . $listing->id, 'public'), 'sort_order' => ++$n]);
        }
    }

    private function own(MarketListing $listing, User $user): void
    {
        if ($listing->user_id !== $user->id) {
            throw new RuntimeException('This is not your listing.');
        }
    }

    /** Seller marks it sold / found / closed, or reopens it. */
    public function setStatus(MarketListing $listing, User $user, string $status): MarketListing
    {
        $this->own($listing, $user);
        if (! in_array($status, ['active', 'sold', 'closed'], true) || in_array($listing->status, ['hidden', 'pending', 'rejected'], true)) {
            throw new RuntimeException('That change is not possible.');
        }
        $listing->update(['status' => $status] + ($status === 'active' ? ['bumped_at' => now()] : []));

        return $listing;
    }

    /** Move it back to the top (once a day) and keep it from expiring. */
    public function bump(MarketListing $listing, User $user): MarketListing
    {
        $this->own($listing, $user);
        if ($listing->bumped_at && $listing->bumped_at->gt(now()->subDay())) {
            throw new RuntimeException('You can renew a listing once a day.');
        }
        if (! in_array($listing->status, ['active', 'closed'], true)) {
            throw new RuntimeException('Only an active or closed listing can be renewed.');
        }
        $listing->update(['status' => 'active', 'bumped_at' => now()]);

        return $listing;
    }

    public function remove(MarketListing $listing, User $user): void
    {
        if ($listing->user_id !== $user->id && ! $user->can('Moderate the marketplace')) {
            throw new RuntimeException('This is not your listing.');
        }
        $listing->delete();
    }

    public function toggleSave(MarketListing $listing, User $user): bool
    {
        $q = DB::table('market_saves')->where('market_listing_id', $listing->id)->where('user_id', $user->id);
        if ($q->exists()) {
            $q->delete();

            return false;
        }
        DB::table('market_saves')->insert(['market_listing_id' => $listing->id, 'user_id' => $user->id, 'created_at' => now()]);

        return true;
    }

    /** Opens (or reuses) a direct chat with the seller / finder and drops the listing into it. */
    public function contact(MarketListing $listing, User $user, ?string $message): \App\Models\ChatConversation
    {
        if ($listing->user_id === $user->id) {
            throw new RuntimeException('This is your own listing.');
        }
        if ($listing->status !== 'active') {
            throw new RuntimeException('This listing is no longer available.');
        }
        $c = $this->chat->direct($user, $listing->user);
        $this->chat->send($c, $user, $message ?: null, null, null, 'listing', [
            'listing_id' => $listing->id, 'title' => $listing->title, 'kind' => $listing->kind, 'price' => $listing->price,
            'photo_url' => $listing->photos->first()?->url(),
        ]);

        return $c;
    }

    // ---- Moderation (web) -------------------------------------------------------------------------------------------

    public function moderate(MarketListing $listing, User $by, string $decision, ?string $note = null): MarketListing
    {
        $status = match ($decision) {
            'approve', 'restore' => 'active',
            'reject' => 'rejected',
            'hide' => 'hidden',
            default => throw new RuntimeException('Unknown decision.'),
        };
        if (in_array($decision, ['reject', 'hide'], true) && blank($note)) {
            throw new RuntimeException('Say why, so the student knows what to change.');
        }
        $listing->update(['status' => $status, 'moderation_note' => $note, 'moderated_by' => $by->id] + ($status === 'active' ? ['bumped_at' => now()] : []));
        if ($listing->user) {
            $this->messenger->pushOnly($listing->user, 'marketplace', match ($decision) {
                'approve', 'restore' => 'Your listing is live',
                'reject' => 'Your listing was not approved',
                default => 'Your listing was hidden',
            }, $listing->title . ($note ? ': ' . $note : ''), ['listing_id' => (string) $listing->id]);
        }

        return $listing;
    }

    public function present(MarketListing $l, User $viewer, bool $full = false): array
    {
        $seller = $l->user;
        $out = [
            'id' => $l->id, 'kind' => $l->kind, 'kind_label' => MarketListing::KINDS[$l->kind] ?? $l->kind, 'title' => $l->title,
            'price' => $l->price, 'negotiable' => $l->negotiable, 'category' => $l->category, 'category_label' => MarketListing::CATEGORIES[$l->category] ?? 'Other',
            'condition' => $l->condition, 'condition_label' => $l->condition ? MarketListing::CONDITIONS[$l->condition] ?? null : null,
            'location' => $l->location, 'happened_on' => $l->happened_on?->toDateString(), 'status' => $l->status,
            'status_label' => MarketListing::STATUSES[$l->status] ?? $l->status,
            'photos' => $l->photos->map(fn ($p) => ['id' => $p->id, 'url' => $p->url()])->values()->all(),
            'mine' => $l->user_id === $viewer->id,
            'seller' => $seller ? ['id' => $seller->id, 'name' => $seller->name, 'photo_url' => $seller->photoUrl(),
                'programme' => $seller->student?->programme?->name, 'level' => $seller->student?->current_level] : null,
            'posted_at' => ($l->bumped_at ?? $l->created_at)?->toIso8601String(),
        ];
        if ($full) {
            $out += [
                'description' => $l->description, 'views' => $l->views, 'moderation_note' => $l->user_id === $viewer->id ? $l->moderation_note : null,
                'saved' => DB::table('market_saves')->where('market_listing_id', $l->id)->where('user_id', $viewer->id)->exists(),
            ];
        }

        return $out;
    }
}
