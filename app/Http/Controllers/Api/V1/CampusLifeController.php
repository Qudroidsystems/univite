<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\FeedComment;
use App\Models\FeedPost;
use App\Models\MarketListing;
use App\Models\User;
use App\Services\Campus\ChatService;
use App\Services\Campus\FeedService;
use App\Services\Campus\MarketService;
use App\Services\Campus\MaterialService;
use App\Services\Campus\PeopleService;
use App\Services\Campus\Realtime;
use App\Services\Campus\ReportService;
use App\Support\MobileAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Campus life in the app: chat and groups, marketplace and lost & found, the feed and events, course materials, people. */
class CampusLifeController extends ApiController
{
    public function __construct(
        private ChatService $chat,
        private MarketService $market,
        private FeedService $feed,
        private MaterialService $materials,
        private PeopleService $people,
        private ReportService $reports,
        private MobileAppSettings $settings,
    ) {
    }

    private function fail(RuntimeException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => ['action' => [$e->getMessage()]]], 422);
    }

    private function feature(string $key): void
    {
        abort_unless($this->settings->feature($key), 403, 'This is switched off by the school.');
    }

    // ---- Chat -----------------------------------------------------------------------------------------------------

    /** GET /chats: the person's chats (automatic groups refreshed first), and open study groups they could join. */
    public function chats(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        $this->chat->sync($user);
        $list = $this->chat->conversations($user);

        return $this->ok($list->all(), ['unread' => (int) $list->sum('unread'), 'open_groups' => $this->chat->openGroups($user)->all()]);
    }

    public function chat(Request $request, ChatConversation $conversation): JsonResponse
    {
        $me = $this->chat->member($conversation, $request->user());

        return $this->ok($this->chat->present($conversation, $request->user(), $me));
    }

    /** GET /chats/{id}/messages?before=&after= */
    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->chat->member($conversation, $request->user());
        $rows = $this->chat->messages($conversation, $request->user(), $request->integer('before') ?: null, $request->integer('after') ?: null);

        return $this->ok($rows->all(), ['has_more' => ! $request->integer('after') && $rows->count() >= 40]);
    }

    /** POST /chats/{id}/messages (multipart when sending a photo or file): body, file, reply_to */
    public function send(Request $request, ChatConversation $conversation): JsonResponse
    {
        $request->validate(['body' => ['nullable', 'string', 'max:4000'], 'file' => ['nullable', 'file', 'max:51200'], 'reply_to' => ['nullable', 'integer']]);
        try {
            $m = $this->chat->send($conversation, $request->user(), $request->input('body'), $request->file('file'), $request->integer('reply_to') ?: null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->chat->presentMessage($m, $request->user()), [], 201);
    }

    public function read(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->chat->markRead($conversation, $request->user(), $request->integer('message_id'));

        return $this->ok(['read' => true]);
    }

    public function deleteMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $this->chat->member($message->conversation, $request->user());
        try {
            $this->chat->delete($message, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['deleted' => true]);
    }

    /** GET /chat/attachments/{message}: a photo or file sent in a chat, only to its members. */
    public function attachment(Request $request, ChatMessage $message): BinaryFileResponse
    {
        $this->chat->member($message->conversation, $request->user());
        abort_unless($message->attachment_path && Storage::disk('local')->exists($message->attachment_path), 404);

        return response()->file(Storage::disk('local')->path($message->attachment_path), ['Content-Disposition' => 'inline; filename="' . addslashes($message->attachment_name ?? 'file') . '"']);
    }

    /** POST /chats/direct {user_id}: open (or reuse) a direct chat. */
    public function direct(Request $request): JsonResponse
    {
        $d = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        try {
            $c = $this->chat->direct($request->user(), User::findOrFail($d['user_id']));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->chat->present($c, $request->user()));
    }

    /** POST /chats/groups {title, description, course_id, open, members: [ids]} */
    public function createGroup(Request $request): JsonResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'open' => ['nullable', 'boolean'], 'members' => ['nullable', 'array', 'max:500'], 'members.*' => ['integer']]);
        try {
            $c = $this->chat->createGroup($request->user(), $d['title'], $d['description'] ?? null, $d['course_id'] ?? null, (bool) ($d['open'] ?? true), $d['members'] ?? []);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->chat->present($c, $request->user()), [], 201);
    }

    public function members(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->chat->member($conversation, $request->user());
        $rows = $conversation->members()->with('user.student.programme', 'user.staffProfile.department')->get()
            ->filter(fn ($m) => $m->user)->sortBy(fn ($m) => [$m->role === 'admin' ? 0 : 1, $m->user->name]);

        return $this->ok($rows->map(fn ($m) => $this->people->card($m->user, $request->user()) + ['role' => $m->role])->values()->all());
    }

    public function join(Request $request, ChatConversation $conversation): JsonResponse
    {
        try {
            $this->chat->join($conversation, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->chat->present($conversation, $request->user()));
    }

    public function addMembers(Request $request, ChatConversation $conversation): JsonResponse
    {
        $d = $request->validate(['members' => ['required', 'array', 'max:500'], 'members.*' => ['integer']]);
        try {
            $n = $this->chat->addMembers($conversation, $request->user(), $d['members']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['added' => $n], ['message' => $n ? "{$n} added." : 'Nobody new could be added (only people taking the course this semester can join a study group).']);
    }

    public function leave(Request $request, ChatConversation $conversation): JsonResponse
    {
        try {
            $this->chat->leave($conversation, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['left' => true]);
    }

    /** PUT /chats/{id} {muted, only_admins_post} */
    public function updateChat(Request $request, ChatConversation $conversation): JsonResponse
    {
        $d = $request->validate(['muted' => ['nullable', 'boolean'], 'only_admins_post' => ['nullable', 'boolean']]);
        try {
            if (array_key_exists('muted', $d)) {
                $this->chat->mute($conversation, $request->user(), (bool) $d['muted']);
            }
            if (array_key_exists('only_admins_post', $d)) {
                $this->chat->setAnnouncementOnly($conversation, $request->user(), (bool) $d['only_admins_post']);
            }
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->chat->present($conversation->fresh(), $request->user()));
    }

    /** POST /realtime/auth {socket_id, channel_name}: lets the phone listen to a chat it belongs to (Pusher private channel). */
    public function realtimeAuth(Request $request, Realtime $realtime): JsonResponse
    {
        $d = $request->validate(['socket_id' => ['required', 'string', 'max:100', 'regex:/^\d+\.\d+$/'], 'channel_name' => ['required', 'string', 'max:100']]);
        abort_unless($realtime->enabled() && preg_match('/^private-chat\.(\d+)$/', $d['channel_name'], $m), 403);
        abort_unless(ChatMember::where('chat_conversation_id', (int) $m[1])->where('user_id', $request->user()->id)->exists(), 403);

        return response()->json(['auth' => $realtime->authorize($d['socket_id'], $d['channel_name'])]);
    }

    // ---- Marketplace and lost & found ------------------------------------------------------------------------------

    public function listings(Request $request): JsonResponse
    {
        $section = $request->query('section') === 'lost_found' ? 'lost_found' : 'market';
        $this->feature($section === 'lost_found' ? 'lost_found' : 'marketplace');
        $page = $this->market->browse($request->user(), $request->only('section', 'kind', 'category', 'q', 'min_price', 'max_price', 'sort'), (int) $request->query('per_page', 20));

        return $this->ok(collect($page->items())->map(fn ($l) => $this->market->present($l, $request->user()))->all(), [
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            'categories' => collect(MarketListing::CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'conditions' => collect(MarketListing::CONDITIONS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
        ]);
    }

    public function myListings(Request $request): JsonResponse
    {
        return $this->ok($this->market->mine($request->user())->map(fn ($l) => $this->market->present($l->setRelation('user', $request->user()), $request->user()))->values()->all(),
            ['saved' => $this->market->saved($request->user())->map(fn ($l) => $this->market->present($l, $request->user()))->values()->all()]);
    }

    public function listing(Request $request, MarketListing $listing): JsonResponse
    {
        abort_unless($this->market->viewable($listing, $request->user()), 404);
        if ($listing->user_id !== $request->user()->id) {
            $listing->increment('views');
        }

        return $this->ok($this->market->present($listing->load('photos', 'user.student.programme'), $request->user(), true));
    }

    private function listingRules(bool $create): array
    {
        return [
            'kind' => [$create ? 'required' : 'nullable', Rule::in(array_keys(MarketListing::KINDS))],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:3000'],
            'category' => ['nullable', Rule::in(array_keys(MarketListing::CATEGORIES))],
            'price' => ['nullable', 'numeric', 'min:0', 'max:50000000'],
            'negotiable' => ['nullable', 'boolean'],
            'condition' => ['nullable', Rule::in(array_keys(MarketListing::CONDITIONS))],
            'location' => ['nullable', 'string', 'max:150'],
            'happened_on' => ['nullable', 'date', 'before_or_equal:today'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'max:6144'],
            'remove_photos' => ['nullable', 'array'],
            'remove_photos.*' => ['integer'],
        ];
    }

    public function createListing(Request $request): JsonResponse
    {
        $d = $request->validate($this->listingRules(true));
        try {
            $l = $this->market->create($request->user(), $d, $request->file('photos', []));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->market->present($l->setRelation('user', $request->user()), $request->user(), true),
            ['message' => $l->status === 'pending' ? 'Posted. It will show once the school approves it.' : 'Posted.'], 201);
    }

    /** POST /market/{listing} (multipart; POST because phones send files that way) */
    public function updateListing(Request $request, MarketListing $listing): JsonResponse
    {
        $d = $request->validate($this->listingRules(false));
        try {
            $l = $this->market->update($listing, $request->user(), $d + $listing->only('title'), $request->file('photos', []), $d['remove_photos'] ?? []);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->market->present($l->load('user'), $request->user(), true));
    }

    /** POST /market/{listing}/status {status: active|sold|closed} or {bump: true} */
    public function listingStatus(Request $request, MarketListing $listing): JsonResponse
    {
        $d = $request->validate(['status' => ['nullable', 'in:active,sold,closed'], 'bump' => ['nullable', 'boolean']]);
        try {
            $l = ! empty($d['bump']) ? $this->market->bump($listing, $request->user()) : $this->market->setStatus($listing, $request->user(), $d['status'] ?? '');
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->market->present($l->load('photos', 'user'), $request->user(), true));
    }

    public function deleteListing(Request $request, MarketListing $listing): JsonResponse
    {
        try {
            $this->market->remove($listing, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['deleted' => true]);
    }

    public function saveListing(Request $request, MarketListing $listing): JsonResponse
    {
        abort_unless($this->market->viewable($listing, $request->user()), 404);

        return $this->ok(['saved' => $this->market->toggleSave($listing, $request->user())]);
    }

    /** POST /market/{listing}/contact {message}: opens a chat with the seller, with the listing in it. */
    public function contact(Request $request, MarketListing $listing): JsonResponse
    {
        abort_unless($this->market->viewable($listing, $request->user()), 404);
        $d = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);
        try {
            $c = $this->market->contact($listing->load('photos', 'user'), $request->user(), $d['message'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->chat->present($c, $request->user()));
    }

    // ---- Feed and events ---------------------------------------------------------------------------------------------

    public function feedIndex(Request $request): JsonResponse
    {
        $only = $request->query('only') === 'events' ? 'events' : null;
        $this->feature($only === 'events' ? 'events' : 'feed');
        $page = $this->feed->feed($request->user(), $only, (int) $request->query('per_page', 15));

        return $this->ok(collect($page->items())->map(fn ($p) => $this->feed->present($p, $request->user()))->all(), [
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'can_post' => collect($this->feed->audiencesFor($request->user()))->map(fn ($opts, $aud) => ['audience' => $aud, 'options' => collect($opts)->map(fn ($l, $id) => ['id' => $id === '' ? null : $id, 'label' => $l])->values()->all()])->values()->all(),
        ]);
    }

    public function post(Request $request, FeedPost $post): JsonResponse
    {
        abort_unless($this->feed->visibleTo($request->user())->whereKey($post->id)->exists(), 404);

        return $this->ok($this->feed->present($post->load('user'), $request->user(), true));
    }

    public function createPost(Request $request): JsonResponse
    {
        $d = $request->validate([
            'audience' => ['required', Rule::in(array_keys(FeedPost::AUDIENCES))], 'audience_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000'], 'image' => ['nullable', 'image', 'max:8192'],
            'is_event' => ['nullable', 'boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'venue' => ['nullable', 'string', 'max:150'], 'pinned' => ['nullable', 'boolean'], 'allow_comments' => ['nullable', 'boolean'],
        ]);
        try {
            $p = $this->feed->create($request->user(), $d, $request->file('image'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->feed->present($p->load('user'), $request->user()), [], 201);
    }

    public function deletePost(Request $request, FeedPost $post): JsonResponse
    {
        try {
            $this->feed->remove($post, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['deleted' => true]);
    }

    public function like(Request $request, FeedPost $post): JsonResponse
    {
        abort_unless($this->feed->visibleTo($request->user())->whereKey($post->id)->exists(), 404);
        $liked = $this->feed->like($post, $request->user());

        return $this->ok(['liked' => $liked, 'likes' => \Illuminate\Support\Facades\DB::table('feed_likes')->where('feed_post_id', $post->id)->count()]);
    }

    public function comment(Request $request, FeedPost $post): JsonResponse
    {
        abort_unless($this->feed->visibleTo($request->user())->whereKey($post->id)->exists(), 404);
        $d = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        try {
            $this->feed->comment($post, $request->user(), $d['body']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->feed->present($post->fresh('user'), $request->user(), true), [], 201);
    }

    public function deleteComment(Request $request, FeedComment $comment): JsonResponse
    {
        try {
            $this->feed->deleteComment($comment, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['deleted' => true]);
    }

    /** POST /feed/{post}/rsvp {status: going|interested|none} */
    public function rsvp(Request $request, FeedPost $post): JsonResponse
    {
        abort_unless($this->feed->visibleTo($request->user())->whereKey($post->id)->exists(), 404);
        $d = $request->validate(['status' => ['required', 'in:going,interested,none']]);
        try {
            $this->feed->rsvp($post, $request->user(), $d['status']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->feed->present($post->fresh('user'), $request->user()));
    }

    // ---- Course materials ----------------------------------------------------------------------------------------------

    public function materialCourses(Request $request): JsonResponse
    {
        $this->feature('materials');

        return $this->ok($this->materials->courses($request->user())->all());
    }

    public function courseMaterials(Request $request, Course $course): JsonResponse
    {
        $this->feature('materials');
        abort_unless($this->materials->canRead($request->user(), $course), 404);

        return $this->ok(CourseMaterial::with('user', 'session')->where('course_id', $course->id)->latest()->get()->map(fn ($m) => $this->materials->present($m, $request->user()))->all(),
            ['course' => ['id' => $course->id, 'code' => $course->code, 'title' => $course->title], 'can_upload' => $this->materials->canManage($request->user(), $course)]);
    }

    /** POST /materials/courses/{course} (multipart): title, description, file or link */
    public function addMaterial(Request $request, Course $course): JsonResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'link' => ['nullable', 'url', 'max:500'],
            'file' => ['nullable', 'file', 'max:' . (MaterialService::MAX_MB * 1024)]]);
        try {
            $m = $this->materials->add($request->user(), $course, $d, $request->file('file'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->materials->present($m->load('user'), $request->user()), [], 201);
    }

    public function downloadMaterial(Request $request, CourseMaterial $material): BinaryFileResponse
    {
        abort_unless($this->materials->canRead($request->user(), $material->course), 404);

        return response()->download($this->materials->file($material), $material->original_name ?? 'material');
    }

    public function deleteMaterial(Request $request, CourseMaterial $material): JsonResponse
    {
        try {
            $this->materials->remove($material, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['deleted' => true]);
    }

    // ---- People ----------------------------------------------------------------------------------------------------------

    public function people(Request $request): JsonResponse
    {
        $this->feature('directory');

        return $this->ok($this->people->search($request->user(), (string) $request->query('q', ''), $request->query('type'))->all());
    }

    public function person(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_disabled || in_array($user->id, \App\Models\UserBlock::where('blocked_user_id', $request->user()->id)->pluck('user_id')->all(), true), 404);

        return $this->ok($this->people->card($user->load('student.programme.department', 'staffProfile.department'), $request->user(), true));
    }

    /** PUT /me/profile {bio, share_lodging, lodging_note} */
    public function updateProfile(Request $request): JsonResponse
    {
        $d = $request->validate(['bio' => ['nullable', 'string', 'max:160'], 'share_lodging' => ['nullable', Rule::in(array_keys(User::SHARE_LODGING))], 'lodging_note' => ['nullable', 'string', 'max:200']]);
        try {
            $user = $this->people->updateProfile($request->user(), $request->only(array_keys($d)));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->people->card($user, $user, true) + ['share_lodging' => $user->share_lodging, 'lodging_note' => $user->lodging_note,
            'share_lodging_options' => collect(User::SHARE_LODGING)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all()]);
    }

    public function myProfile(Request $request): JsonResponse
    {
        $user = $request->user()->load('student.programme.department', 'staffProfile.department');

        return $this->ok($this->people->card($user, $user, true) + ['share_lodging' => $user->share_lodging, 'lodging_note' => $user->lodging_note,
            'share_lodging_options' => collect(User::SHARE_LODGING)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all()]);
    }

    /** POST /me/photo (multipart: photo) — the picture people see in the app. DELETE removes it. */
    public function photo(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:4096']]);
        try {
            $user = $this->people->setPhoto($request->user(), $request->file('photo'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['photo_url' => $user->photoUrl()]);
    }

    public function removePhoto(Request $request): JsonResponse
    {
        $user = $this->people->setPhoto($request->user(), null);

        return $this->ok(['photo_url' => $user->photoUrl()]);
    }

    /** POST /people/{user}/block {block: bool} */
    public function block(Request $request, User $user): JsonResponse
    {
        $d = $request->validate(['block' => ['required', 'boolean']]);
        try {
            $this->people->block($request->user(), $user, (bool) $d['block']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['blocked' => (bool) $d['block']]);
    }

    /** POST /reports {type: message|listing|post|comment|user, id, reason, details} */
    public function report(Request $request): JsonResponse
    {
        $d = $request->validate(['type' => ['required', Rule::in(array_keys(ContentReport::TYPES))], 'id' => ['required', 'integer'],
            'reason' => ['required', Rule::in(array_keys(ContentReport::REASONS))], 'details' => ['nullable', 'string', 'max:1000']]);
        try {
            $this->reports->report($request->user(), $d['type'], $d['id'], $d['reason'], $d['details'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok(['reported' => true], ['message' => 'Thank you. The school will look at it.'], 201);
    }

    public function reportReasons(): JsonResponse
    {
        return $this->ok(collect(ContentReport::REASONS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all());
    }
}
