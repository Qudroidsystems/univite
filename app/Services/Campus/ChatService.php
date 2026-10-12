<?php

namespace App\Services\Campus;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\RegistrationForm;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Messenger;
use App\Services\RegistrationService;
use App\Support\MobileAppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chat in the mobile app.
 *
 * - Direct chats between two people (not when either has blocked the other).
 * - A group for every department: its students and staff, kept up to date automatically.
 * - A group for every course each semester: the lecturers assigned and the students whose course form includes it.
 * - Study groups students create inside a course (open to anyone taking it, or invite-only), and other groups.
 *
 * Automatic memberships are refreshed each time a person opens their chats (sync()), so moving department or
 * registering a course needs no extra step. Files are kept on the private disk and served only to members.
 */
class ChatService
{
    public function __construct(
        private RegistrationService $registration,
        private MobileAppSettings $settings,
        private Messenger $messenger,
        private Realtime $realtime,
    ) {
    }

    // ---- Automatic groups ------------------------------------------------------------------------------------

    /** Put the person in their department group and this semester's course groups, and out of ones that no longer apply. */
    public function sync(User $user): void
    {
        $user->loadMissing('student.programme', 'staffProfile');

        $keep = [];
        if ($this->settings->feature('department_groups') && ($deptId = $user->departmentId())) {
            $dept = Department::find($deptId);
            if ($dept) {
                $conv = ChatConversation::firstOrCreate(['type' => 'department', 'department_id' => $dept->id],
                    ['title' => $dept->name, 'description' => 'Everyone in ' . $dept->name . ': students and staff.']);
                $this->autoJoin($conv, $user, $user->student ? 'member' : 'admin');
                $keep[] = $conv->id;
            }
        }
        ChatMember::where('user_id', $user->id)->where('auto', true)
            ->whereHas('conversation', fn ($q) => $q->where('type', 'department'))->whereNotIn('chat_conversation_id', $keep)->delete();

        $ctx = $this->registration->context();
        if (! $ctx) {
            return;
        }
        $courseIds = collect();
        if ($this->settings->feature('course_groups')) {
            if ($user->student) {
                $courseIds = RegistrationForm::where('student_id', $user->student->id)->where('academic_session_id', $ctx->session->id)
                    ->where('semester_type_id', $ctx->type->id)->whereIn('status', ['submitted', 'approved'])
                    ->with('courses')->get()->flatMap(fn ($f) => $f->courses->pluck('course_id'));
            } else {
                $courseIds = CourseAssignment::where('user_id', $user->id)->where('academic_session_id', $ctx->session->id)
                    ->where('semester_type_id', $ctx->type->id)->pluck('course_id');
            }
        }
        $termKeep = [];
        foreach (Course::whereIn('id', $courseIds->unique())->get() as $course) {
            $conv = ChatConversation::firstOrCreate(
                ['type' => 'course', 'course_id' => $course->id, 'academic_session_id' => $ctx->session->id, 'semester_type_id' => $ctx->type->id],
                ['title' => "{$course->code} · {$course->title}", 'description' => "{$course->code} for {$ctx->label()}: the lecturers and every student taking it."],
            );
            $this->autoJoin($conv, $user, $user->student ? 'member' : 'admin');
            $termKeep[] = $conv->id;
        }
        // Dropped a course this semester: leave its group. Earlier semesters' groups stay readable.
        ChatMember::where('user_id', $user->id)->where('auto', true)
            ->whereHas('conversation', fn ($q) => $q->where('type', 'course')->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id))
            ->whereNotIn('chat_conversation_id', $termKeep)->delete();
    }

    private function autoJoin(ChatConversation $conv, User $user, string $role): void
    {
        $m = ChatMember::firstOrCreate(['chat_conversation_id' => $conv->id, 'user_id' => $user->id], ['role' => $role, 'auto' => true,
            'last_read_message_id' => (int) $conv->messages()->max('id')]);
        if (! $m->auto) {
            $m->update(['auto' => true]);
        }
    }

    // ---- Reading -------------------------------------------------------------------------------------------------

    /** The person's chats, newest activity first, each with its unread count and last message. */
    public function conversations(User $user): Collection
    {
        $memberships = ChatMember::where('user_id', $user->id)->with(['conversation.latestMessage.user', 'conversation.course'])->get()
            ->filter(fn ($m) => $m->conversation && $this->visible($m->conversation));
        $hidden = UserBlock::hiddenFor($user->id);

        $unread = ChatMessage::query()
            ->join('chat_members', fn ($j) => $j->on('chat_members.chat_conversation_id', '=', 'chat_messages.chat_conversation_id')->where('chat_members.user_id', $user->id))
            ->whereColumn('chat_messages.id', '>', 'chat_members.last_read_message_id')
            ->where(fn ($q) => $q->whereNull('chat_messages.user_id')->orWhere('chat_messages.user_id', '!=', $user->id))
            ->when($hidden, fn ($q) => $q->where(fn ($w) => $w->whereNull('chat_messages.user_id')->orWhereNotIn('chat_messages.user_id', $hidden)))
            ->groupBy('chat_messages.chat_conversation_id')
            ->selectRaw('chat_messages.chat_conversation_id as cid, count(*) as n')->pluck('n', 'cid');

        $directOthers = $this->otherMembers($memberships->pluck('conversation')->where('type', 'direct'), $user);
        $counts = ChatMember::whereIn('chat_conversation_id', $memberships->pluck('chat_conversation_id'))
            ->groupBy('chat_conversation_id')->selectRaw('chat_conversation_id, count(*) as n')->pluck('n', 'chat_conversation_id');

        return $memberships
            ->reject(fn ($m) => $m->conversation->type === 'direct' && ($other = $directOthers->get($m->chat_conversation_id)) && in_array($other->id, $hidden, true))
            // A direct chat nobody has written in yet does not clutter the list.
            ->reject(fn ($m) => $m->conversation->type === 'direct' && ! $m->conversation->latestMessage)
            ->map(fn ($m) => $this->present($m->conversation, $user, $m, (int) ($unread[$m->chat_conversation_id] ?? 0), $directOthers->get($m->chat_conversation_id), (int) ($counts[$m->chat_conversation_id] ?? 0)))
            ->sortByDesc(fn ($c) => $c['last_activity'] ?? '')
            ->values();
    }

    /** @return Collection<int, User> conversation id => the other person, for direct chats */
    private function otherMembers(Collection $convs, User $user): Collection
    {
        if ($convs->isEmpty()) {
            return collect();
        }

        return ChatMember::with('user.student')->whereIn('chat_conversation_id', $convs->pluck('id'))->where('user_id', '!=', $user->id)->get()
            ->mapWithKeys(fn ($m) => [$m->chat_conversation_id => $m->user])->filter();
    }

    /** Features switched off in Settings → Mobile App hide their kind of chat. */
    private function visible(ChatConversation $c): bool
    {
        return match ($c->type) {
            'direct' => $this->settings->feature('chat'),
            'department' => $this->settings->feature('department_groups'),
            'course' => $this->settings->feature('course_groups'),
            'study' => $this->settings->feature('study_groups'),
            default => $this->settings->feature('chat'),
        };
    }

    public function present(ChatConversation $c, User $user, ?ChatMember $me = null, int $unread = 0, ?User $other = null, ?int $members = null): array
    {
        $me ??= $c->members()->where('user_id', $user->id)->first();
        if ($c->type === 'direct' && ! $other) {
            $other = $this->otherMembers(collect([$c]), $user)->first();
        }
        $last = $c->relationLoaded('latestMessage') ? $c->latestMessage : $c->latestMessage()->with('user')->first();

        return [
            'id' => $c->id,
            'type' => $c->type,
            'type_label' => ChatConversation::TYPES[$c->type] ?? 'Group',
            'title' => $c->type === 'direct' ? ($other?->name ?? 'Former member') : $c->title,
            'description' => $c->description,
            'photo_url' => $c->type === 'direct' ? $other?->photoUrl() : null,
            'other_user_id' => $other?->id,
            'course' => $c->course ? ['id' => $c->course->id, 'code' => $c->course->code] : null,
            'members' => $members ?? $c->members()->count(),
            'unread' => $unread,
            'muted' => (bool) $me?->muted,
            'role' => $me?->role,
            'can_post' => $me !== null && (! $c->only_admins_post || $me->role === 'admin'),
            'can_leave' => $me !== null && ! $c->isAutomatic() && $c->type !== 'direct',
            'is_open' => $c->is_open,
            'only_admins_post' => $c->only_admins_post,
            'last_message' => $last ? ['body' => $this->preview($last), 'kind' => $last->kind, 'at' => $last->created_at?->toIso8601String(),
                'sender' => $last->user_id === $user->id ? 'You' : ($last->user?->name ? Str::of($last->user->name)->explode(' ')->first() : null)] : null,
            'last_activity' => ($c->last_message_at ?? $c->created_at)?->toIso8601String(),
        ];
    }

    private function preview(ChatMessage $m): string
    {
        if ($m->trashed()) {
            return 'Message deleted';
        }

        return match ($m->kind) {
            'image' => '📷 Photo' . ($m->body ? ': ' . Str::limit($m->body, 60) : ''),
            'file' => '📎 ' . ($m->attachment_name ?? 'File'),
            'listing' => '🛍 ' . ($m->meta['title'] ?? 'Listing'),
            default => Str::limit((string) $m->body, 90),
        };
    }

    public function member(ChatConversation $c, User $user): ChatMember
    {
        $m = $c->members()->where('user_id', $user->id)->first();
        if (! $m || ! $this->visible($c)) {
            abort(404);
        }

        return $m;
    }

    /**
     * Messages, newest last. $before loads older ones (scrolling up); $after only newer ones (checking for new).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function messages(ChatConversation $c, User $user, ?int $before = null, ?int $after = null, int $limit = 40): Collection
    {
        $hidden = UserBlock::hiddenFor($user->id);
        $q = $c->messages()->withTrashed()->with(['user.student', 'replyTo.user'])
            ->when($hidden, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $hidden)));
        if ($after) {
            $rows = $q->where('id', '>', $after)->orderBy('id')->limit(200)->get();
        } else {
            $rows = $q->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        }

        return $rows->map(fn ($m) => $this->presentMessage($m, $user));
    }

    public function presentMessage(ChatMessage $m, User $user): array
    {
        $deleted = $m->trashed();

        return [
            'id' => $m->id,
            'conversation_id' => $m->chat_conversation_id,
            'kind' => $deleted ? 'deleted' : $m->kind,
            'body' => $deleted ? null : $m->body,
            'attachment' => ! $deleted && $m->attachment_path ? [
                'url' => route('api.chat.attachment', $m->id), 'name' => $m->attachment_name, 'size' => $m->attachment_size,
                'is_image' => $m->kind === 'image',
            ] : null,
            'meta' => $deleted ? null : $m->meta,
            'sender' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'photo_url' => $m->user->photoUrl()] : null,
            'mine' => $m->user_id === $user->id,
            'reply_to' => $m->replyTo ? ['id' => $m->replyTo->id, 'body' => $m->replyTo->trashed() ? 'Message deleted' : $this->preview($m->replyTo), 'sender' => $m->replyTo->user?->name] : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    // ---- Starting chats --------------------------------------------------------------------------------------------

    /** Why $from cannot start a direct chat with $to, or null. */
    public function cannotMessage(User $from, User $to): ?string
    {
        if (! $this->settings->feature('chat')) {
            return 'Chat is switched off.';
        }
        if ($from->id === $to->id) {
            return 'That is you.';
        }
        if ($to->is_disabled) {
            return 'This account is not active.';
        }
        if (in_array($to->id, UserBlock::hiddenFor($from->id), true)) {
            return 'You cannot message this person.';
        }
        if ($from->student && ! $to->student && ! $this->settings->on('students_message_staff')) {
            $shared = ChatMember::where('user_id', $from->id)->whereIn('chat_conversation_id', ChatMember::where('user_id', $to->id)->select('chat_conversation_id'))->exists();
            if (! $shared) {
                return 'Students cannot start chats with staff. Use your course or department group.';
            }
        }

        return null;
    }

    public function direct(User $from, User $to): ChatConversation
    {
        if ($why = $this->cannotMessage($from, $to)) {
            throw new RuntimeException($why);
        }
        $key = min($from->id, $to->id) . '-' . max($from->id, $to->id);

        return DB::transaction(function () use ($key, $from, $to) {
            $c = ChatConversation::firstOrCreate(['direct_key' => $key], ['type' => 'direct', 'created_by' => $from->id]);
            foreach ([$from, $to] as $u) {
                ChatMember::firstOrCreate(['chat_conversation_id' => $c->id, 'user_id' => $u->id], ['role' => 'member']);
            }

            return $c;
        });
    }

    /**
     * A study group inside a course (the creator must be taking or teaching it this semester), or a plain group.
     *
     * @param  int[]  $memberIds  people to add straight away
     */
    public function createGroup(User $user, string $title, ?string $description, ?int $courseId, bool $open, array $memberIds = []): ChatConversation
    {
        if ($courseId && ! $this->settings->feature('study_groups')) {
            throw new RuntimeException('Study groups are switched off.');
        }
        if (! $courseId && ! $this->settings->feature('chat')) {
            throw new RuntimeException('Chat is switched off.');
        }
        if ($courseId && ! $this->takesOrTeaches($user, $courseId)) {
            throw new RuntimeException('You can only start a study group for a course you are taking or teaching this semester.');
        }
        $ctx = $this->registration->context();
        $max = $this->settings->int('study_group_max');
        $memberIds = collect($memberIds)->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $user->id)->unique()->take(max(0, $max - 1));
        $hidden = UserBlock::hiddenFor($user->id);

        return DB::transaction(function () use ($user, $title, $description, $courseId, $open, $memberIds, $ctx, $hidden) {
            $c = ChatConversation::create([
                'type' => $courseId ? 'study' : 'group', 'title' => mb_substr(trim($title), 0, 120), 'description' => $description ? mb_substr($description, 0, 500) : null,
                'course_id' => $courseId, 'academic_session_id' => $courseId ? $ctx?->session->id : null, 'semester_type_id' => $courseId ? $ctx?->type->id : null,
                'created_by' => $user->id, 'is_open' => $courseId ? $open : false,
            ]);
            ChatMember::create(['chat_conversation_id' => $c->id, 'user_id' => $user->id, 'role' => 'admin']);
            foreach (User::whereIn('id', $memberIds)->where('is_disabled', false)->get() as $u) {
                if (in_array($u->id, $hidden, true) || ($courseId && ! $this->takesOrTeaches($u, $courseId))) {
                    continue;
                }
                ChatMember::create(['chat_conversation_id' => $c->id, 'user_id' => $u->id, 'role' => 'member']);
            }
            $this->system($c, "{$user->name} created the group.");

            return $c;
        });
    }

    /** Whether the person is taking (on this semester's submitted / approved course form) or teaching the course this semester. */
    public function takesOrTeaches(User $user, int $courseId): bool
    {
        $ctx = $this->registration->context();
        if (! $ctx) {
            return false;
        }
        if ($user->student) {
            return RegistrationForm::where('student_id', $user->student->id)->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)
                ->whereIn('status', ['submitted', 'approved'])->whereHas('courses', fn ($q) => $q->where('course_id', $courseId))->exists();
        }

        return CourseAssignment::where('user_id', $user->id)->where('course_id', $courseId)->where('academic_session_id', $ctx->session->id)
            ->where('semester_type_id', $ctx->type->id)->exists();
    }

    /** Open study groups for the courses the person takes this semester that they are not in yet. */
    public function openGroups(User $user): Collection
    {
        $mine = ChatMember::where('user_id', $user->id)->pluck('chat_conversation_id');
        $ctx = $this->registration->context();
        if (! $ctx || ! $this->settings->feature('study_groups')) {
            return collect();
        }

        return ChatConversation::with('course')->withCount('members')->where('type', 'study')->where('is_open', true)
            ->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->whereNotIn('id', $mine)
            ->latest('last_message_at')->limit(100)->get()
            ->filter(fn ($c) => $this->takesOrTeaches($user, (int) $c->course_id))
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'description' => $c->description, 'course' => ['id' => $c->course?->id, 'code' => $c->course?->code], 'members' => $c->members_count])
            ->values();
    }

    public function join(ChatConversation $c, User $user): void
    {
        if ($c->type !== 'study' || ! $c->is_open) {
            throw new RuntimeException('This group is by invitation only.');
        }
        if (! $this->takesOrTeaches($user, (int) $c->course_id)) {
            throw new RuntimeException('Only people taking ' . ($c->course?->code ?? 'the course') . ' this semester can join.');
        }
        if ($c->members()->count() >= $this->settings->int('study_group_max')) {
            throw new RuntimeException('This group is full.');
        }
        ChatMember::firstOrCreate(['chat_conversation_id' => $c->id, 'user_id' => $user->id], ['role' => 'member', 'last_read_message_id' => (int) $c->messages()->max('id')]);
        $this->system($c, "{$user->name} joined.");
    }

    /** Group admins add people (study groups: only people taking the course). */
    public function addMembers(ChatConversation $c, User $by, array $userIds): int
    {
        $me = $this->member($c, $by);
        if (! in_array($c->type, ['study', 'group'], true) || $me->role !== 'admin') {
            throw new RuntimeException('Only the group\'s admins can add people.');
        }
        $room = $this->settings->int('study_group_max') - $c->members()->count();
        $hidden = UserBlock::hiddenFor($by->id);
        $added = 0;
        foreach (User::whereIn('id', $userIds)->where('is_disabled', false)->get() as $u) {
            if ($room <= 0 || in_array($u->id, $hidden, true) || ($c->course_id && ! $this->takesOrTeaches($u, (int) $c->course_id))) {
                continue;
            }
            $m = ChatMember::firstOrCreate(['chat_conversation_id' => $c->id, 'user_id' => $u->id], ['role' => 'member']);
            if ($m->wasRecentlyCreated) {
                $added++;
                $room--;
                $this->system($c, "{$by->name} added {$u->name}.");
            }
        }

        return $added;
    }

    public function leave(ChatConversation $c, User $user): void
    {
        $m = $this->member($c, $user);
        if ($c->isAutomatic() || $c->type === 'direct') {
            throw new RuntimeException('You cannot leave this chat. You can mute it instead.');
        }
        $m->delete();
        $this->system($c, "{$user->name} left.");
        // The last admin leaving hands the group to the longest-standing member.
        if ($m->role === 'admin' && ! $c->members()->where('role', 'admin')->exists()) {
            $c->members()->orderBy('id')->first()?->update(['role' => 'admin']);
        }
    }

    public function mute(ChatConversation $c, User $user, bool $muted): void
    {
        $this->member($c, $user)->update(['muted' => $muted]);
    }

    /** Group admins (and lecturers in course groups) can make a group announcement-only. */
    public function setAnnouncementOnly(ChatConversation $c, User $user, bool $on): void
    {
        if ($this->member($c, $user)->role !== 'admin' || $c->type === 'direct') {
            throw new RuntimeException('Only the group\'s admins can change this.');
        }
        $c->update(['only_admins_post' => $on]);
        $this->system($c, $on ? 'Only admins can send messages now.' : 'Everyone can send messages again.');
    }

    // ---- Sending ----------------------------------------------------------------------------------------------------

    public function send(ChatConversation $c, User $user, ?string $body, ?UploadedFile $file = null, ?int $replyTo = null, string $kind = 'text', ?array $meta = null): ChatMessage
    {
        $me = $this->member($c, $user);
        if ($c->only_admins_post && $me->role !== 'admin') {
            throw new RuntimeException('Only the group\'s admins can send messages here.');
        }
        $body = $body !== null ? trim($body) : null;
        if (($body === null || $body === '') && ! $file && $kind !== 'listing') {
            throw new RuntimeException('Type a message.');
        }
        if ($c->type === 'direct') {
            $other = $c->members()->where('user_id', '!=', $user->id)->with('user')->first()?->user;
            if (! $other || in_array($other->id, UserBlock::hiddenFor($user->id), true)) {
                throw new RuntimeException('You cannot message this person.');
            }
        }

        $data = ['chat_conversation_id' => $c->id, 'user_id' => $user->id, 'kind' => $kind, 'body' => $body !== '' ? $body : null, 'meta' => $meta,
            'reply_to_id' => $replyTo && ChatMessage::where('id', $replyTo)->where('chat_conversation_id', $c->id)->exists() ? $replyTo : null];
        if ($file) {
            $maxKb = $this->settings->int('chat_max_upload_mb') * 1024;
            if ($file->getSize() > $maxKb * 1024) {
                throw new RuntimeException('That file is too big. The largest allowed is ' . $this->settings->int('chat_max_upload_mb') . ' MB.');
            }
            $isImage = str_starts_with((string) $file->getMimeType(), 'image/');
            $data += ['attachment_path' => $file->store('chat/' . $c->id, 'local'), 'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'attachment_size' => $file->getSize()];
            $data['kind'] = $isImage ? 'image' : 'file';
        }

        $message = DB::transaction(function () use ($c, $me, $data) {
            $m = ChatMessage::create($data);
            $c->update(['last_message_at' => now()]);
            $me->update(['last_read_message_id' => $m->id]);

            return $m;
        });

        $this->announce($c, $message->load('user'));

        return $message;
    }

    /** A grey line in the chat ("Ada joined."). */
    public function system(ChatConversation $c, string $text): ChatMessage
    {
        $m = ChatMessage::create(['chat_conversation_id' => $c->id, 'user_id' => null, 'kind' => 'system', 'body' => $text]);
        $c->update(['last_message_at' => now()]);

        return $m;
    }

    /**
     * Live update to everyone with the chat open, and a push to members' phones. In department and course groups only
     * messages from staff (announcements) are pushed, so a busy class group does not buzz hundreds of phones all day.
     */
    private function announce(ChatConversation $c, ChatMessage $m): void
    {
        $this->realtime->trigger(['private-chat.' . $c->id], 'message', ['conversation_id' => $c->id, 'message_id' => $m->id]);

        if ($c->isAutomatic() && $m->user?->student) {
            return;
        }
        $title = $c->type === 'direct' ? $m->user->name : ($c->title . ': ' . Str::of($m->user->name)->explode(' ')->first());
        $hidden = UserBlock::hiddenFor($m->user_id);
        $c->members()->where('user_id', '!=', $m->user_id)->where('muted', false)->whereNotIn('user_id', $hidden)->with('user')->get()
            ->each(fn ($member) => $member->user && $this->messenger->pushOnly($member->user, 'chat', $title, $this->preview($m), ['conversation_id' => (string) $c->id]));
    }

    public function markRead(ChatConversation $c, User $user, int $messageId): void
    {
        $m = $this->member($c, $user);
        if ($messageId > $m->last_read_message_id) {
            $m->update(['last_read_message_id' => min($messageId, (int) $c->messages()->withTrashed()->max('id'))]);
        }
    }

    /** The sender, a group admin, or someone who handles reports may delete a message. */
    public function delete(ChatMessage $m, User $user): void
    {
        $c = $m->conversation;
        $role = $c->members()->where('user_id', $user->id)->value('role');
        $mayModerate = $user->can('Handle reports');
        if ($m->user_id !== $user->id && ! ($role === 'admin' && $c->type !== 'direct') && ! $mayModerate) {
            throw new RuntimeException('You can only delete your own messages.');
        }
        $m->delete();
        $this->realtime->trigger(['private-chat.' . $c->id], 'deleted', ['conversation_id' => $c->id, 'message_id' => $m->id]);
    }

    public function totalUnread(User $user): int
    {
        return (int) $this->conversations($user)->sum('unread');
    }
}
