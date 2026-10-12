<?php

namespace App\Services\Campus;

use App\Models\FeedComment;
use App\Models\FeedPost;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Messenger;
use App\Support\MobileAppSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The campus feed: posts and events for everyone, all students, all staff, one faculty or one department.
 * Staff with "Post to the campus feed" post to any audience; other staff to their own department; students to their
 * department when the school allows it. Readers like, comment and (on events) say they are going.
 */
class FeedService
{
    public function __construct(private MobileAppSettings $settings, private Messenger $messenger)
    {
    }

    /** Posts the person may see. */
    public function visibleTo(User $user): Builder
    {
        $user->loadMissing('student.programme.department', 'staffProfile.department');
        $deptId = $user->departmentId();
        $facultyId = $user->student?->programme?->department?->faculty_id ?? $user->staffProfile?->department?->faculty_id;

        return FeedPost::query()->where('status', 'published')
            ->where(function ($q) use ($user, $deptId, $facultyId) {
                $q->where('audience', 'everyone')
                    ->orWhere('audience', $user->student ? 'students' : 'staff')
                    ->orWhere(fn ($w) => $w->where('audience', 'faculty')->where('audience_id', $facultyId))
                    ->orWhere(fn ($w) => $w->where('audience', 'department')->where('audience_id', $deptId))
                    ->orWhere('user_id', $user->id);
            })
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', UserBlock::hiddenFor($user->id)));
    }

    public function feed(User $user, ?string $only = null, int $perPage = 15): LengthAwarePaginator
    {
        return $this->visibleTo($user)->with('user.student')->withCount(['comments'])
            ->when($only === 'events', fn ($q) => $q->where('is_event', true)->where(fn ($w) => $w->whereNull('ends_at')->where('starts_at', '>=', now()->subHours(6))->orWhere('ends_at', '>=', now()))->reorder()->orderBy('starts_at'))
            ->when($only !== 'events', fn ($q) => $q->orderByDesc('pinned')->latest())
            ->paginate(min(30, max(5, $perPage)));
    }

    /** Where the person may post: [audience => [id => label]] ('everyone' etc. with a single null key). */
    public function audiencesFor(User $user): array
    {
        if (! $this->settings->feature('feed')) {
            return [];
        }
        if ($user->can('Post to the campus feed')) {
            return [
                'everyone' => [null => 'Everyone'], 'students' => [null => 'All students'], 'staff' => [null => 'All staff'],
                'faculty' => \App\Models\Faculty::orderBy('name')->pluck('name', 'id')->all(),
                'department' => \App\Models\Department::orderBy('name')->pluck('name', 'id')->all(),
            ];
        }
        $deptId = $user->departmentId();
        if (! $deptId || ($user->student && ! $this->settings->on('students_post_feed'))) {
            return [];
        }

        return ['department' => [$deptId => \App\Models\Department::find($deptId)?->name ?? 'My department']];
    }

    public function create(User $user, array $d, ?UploadedFile $image = null): FeedPost
    {
        $allowed = $this->audiencesFor($user);
        $audience = $d['audience'] ?? 'department';
        $audienceId = in_array($audience, ['faculty', 'department'], true) ? (int) ($d['audience_id'] ?? 0) : null;
        if (! isset($allowed[$audience]) || ($audienceId !== null && ! array_key_exists($audienceId, $allowed[$audience]))) {
            throw new RuntimeException('You cannot post to that audience.');
        }
        $isEvent = ! empty($d['is_event']) && $this->settings->feature('events');
        if ($isEvent && empty($d['starts_at'])) {
            throw new RuntimeException('Give the event a start date and time.');
        }
        if ($image && (! str_starts_with((string) $image->getMimeType(), 'image/') || $image->getSize() > 8 * 1024 * 1024)) {
            throw new RuntimeException('The picture must be an image of up to 8 MB.');
        }

        $post = FeedPost::create([
            'user_id' => $user->id, 'audience' => $audience, 'audience_id' => $audienceId,
            'title' => isset($d['title']) ? mb_substr(trim((string) $d['title']), 0, 150) : null,
            'body' => mb_substr(trim((string) $d['body']), 0, 5000),
            'image_path' => $image?->store('feed', 'public'),
            'is_event' => $isEvent, 'starts_at' => $isEvent ? $d['starts_at'] : null, 'ends_at' => $isEvent ? ($d['ends_at'] ?? null) : null,
            'venue' => $isEvent && isset($d['venue']) ? mb_substr((string) $d['venue'], 0, 150) : null,
            'pinned' => ! empty($d['pinned']) && $user->can('Post to the campus feed'),
            'allow_comments' => ! array_key_exists('allow_comments', $d) || ! empty($d['allow_comments']),
        ]);

        return $post;
    }

    public function update(FeedPost $post, User $user, array $d): FeedPost
    {
        if ($post->user_id !== $user->id && ! $user->can('Moderate the campus feed')) {
            throw new RuntimeException('This is not your post.');
        }
        $post->update(array_filter([
            'title' => array_key_exists('title', $d) ? mb_substr(trim((string) $d['title']), 0, 150) : null,
            'body' => isset($d['body']) ? mb_substr(trim((string) $d['body']), 0, 5000) : null,
            'venue' => $d['venue'] ?? null, 'starts_at' => $d['starts_at'] ?? null, 'ends_at' => $d['ends_at'] ?? null,
        ], fn ($v) => $v !== null) + (array_key_exists('allow_comments', $d) ? ['allow_comments' => (bool) $d['allow_comments']] : []));

        return $post;
    }

    public function remove(FeedPost $post, User $user): void
    {
        if ($post->user_id !== $user->id && ! $user->can('Moderate the campus feed')) {
            throw new RuntimeException('This is not your post.');
        }
        $post->delete();
    }

    public function like(FeedPost $post, User $user): bool
    {
        $q = DB::table('feed_likes')->where('feed_post_id', $post->id)->where('user_id', $user->id);
        if ($q->exists()) {
            $q->delete();

            return false;
        }
        DB::table('feed_likes')->insert(['feed_post_id' => $post->id, 'user_id' => $user->id, 'created_at' => now()]);

        return true;
    }

    public function comment(FeedPost $post, User $user, string $body): FeedComment
    {
        if (! $post->allow_comments) {
            throw new RuntimeException('Comments are closed on this post.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Write a comment.');
        }
        $c = FeedComment::create(['feed_post_id' => $post->id, 'user_id' => $user->id, 'body' => mb_substr($body, 0, 2000)]);
        if ($post->user && $post->user_id !== $user->id && ! in_array($post->user_id, UserBlock::hiddenFor($user->id), true)) {
            $this->messenger->pushOnly($post->user, 'feed', $user->name . ' commented', mb_substr($body, 0, 140), ['post_id' => (string) $post->id]);
        }

        return $c;
    }

    public function deleteComment(FeedComment $c, User $user): void
    {
        if ($c->user_id !== $user->id && $c->post?->user_id !== $user->id && ! $user->can('Moderate the campus feed')) {
            throw new RuntimeException('You can only delete your own comments.');
        }
        $c->delete();
    }

    public function rsvp(FeedPost $post, User $user, ?string $status): ?string
    {
        if (! $post->is_event) {
            throw new RuntimeException('This is not an event.');
        }
        if ($status === null || $status === 'none') {
            DB::table('event_rsvps')->where('feed_post_id', $post->id)->where('user_id', $user->id)->delete();

            return null;
        }
        if (! in_array($status, ['going', 'interested'], true)) {
            throw new RuntimeException('Choose going or interested.');
        }
        DB::table('event_rsvps')->updateOrInsert(['feed_post_id' => $post->id, 'user_id' => $user->id], ['status' => $status, 'updated_at' => now(), 'created_at' => now()]);

        return $status;
    }

    public function present(FeedPost $p, User $viewer, bool $withComments = false): array
    {
        $likes = DB::table('feed_likes')->where('feed_post_id', $p->id);
        $rsvps = $p->is_event ? DB::table('event_rsvps')->where('feed_post_id', $p->id)->get() : collect();
        $out = [
            'id' => $p->id, 'title' => $p->title, 'body' => $p->body, 'image_url' => $p->imageUrl(),
            'author' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'photo_url' => $p->user->photoUrl(), 'is_staff' => ! $p->user->student] : ['id' => null, 'name' => 'The school', 'photo_url' => null, 'is_staff' => true],
            'audience' => $p->audience, 'audience_label' => $p->audienceLabel(), 'pinned' => $p->pinned,
            'is_event' => $p->is_event, 'starts_at' => $p->starts_at?->toIso8601String(), 'ends_at' => $p->ends_at?->toIso8601String(), 'venue' => $p->venue,
            'going' => $rsvps->where('status', 'going')->count(), 'interested' => $rsvps->where('status', 'interested')->count(),
            'my_rsvp' => $rsvps->firstWhere('user_id', $viewer->id)?->status,
            'likes' => (clone $likes)->count(), 'liked' => (clone $likes)->where('user_id', $viewer->id)->exists(),
            'comments' => $p->comments_count ?? $p->comments()->count(), 'allow_comments' => $p->allow_comments,
            'mine' => $p->user_id === $viewer->id, 'can_delete' => $p->user_id === $viewer->id || $viewer->can('Moderate the campus feed'),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
        if ($withComments) {
            $hidden = UserBlock::hiddenFor($viewer->id);
            $out['comment_list'] = $p->comments()->with('user.student')->whereNotIn('user_id', $hidden)->oldest()->limit(200)->get()->map(fn ($c) => [
                'id' => $c->id, 'body' => $c->body, 'created_at' => $c->created_at?->toIso8601String(),
                'author' => ['id' => $c->user?->id, 'name' => $c->user?->name ?? 'Former member', 'photo_url' => $c->user?->photoUrl()],
                'can_delete' => $c->user_id === $viewer->id || $p->user_id === $viewer->id || $viewer->can('Moderate the campus feed'),
            ])->values()->all();
        }

        return $out;
    }
}
