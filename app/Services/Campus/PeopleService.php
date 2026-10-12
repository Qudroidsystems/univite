<?php

namespace App\Services\Campus;

use App\Models\AcademicSession;
use App\Models\BedAllocation;
use App\Models\ChatMember;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Models\UserBlock;
use App\Support\MobileAppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Finding people in the app, their profile cards, the profile picture they choose, blocking, and where students live.
 *
 * Where a student lives is shown only if she switched it on: to everyone in the school, or only to people in her
 * department or taking one of her courses ("mates"). It is her hostel bed this session when she has one, plus any note
 * she adds (an off-campus address, a landmark).
 */
class PeopleService
{
    public function __construct(private MobileAppSettings $settings, private ChatService $chat)
    {
    }

    public function search(User $viewer, string $q, ?string $type = null, int $limit = 30): Collection
    {
        $q = trim($q);
        $hidden = UserBlock::hiddenFor($viewer->id);

        return User::with(['student.programme.department', 'staffProfile.department'])
            ->where('is_disabled', false)->where('id', '!=', $viewer->id)->whereNotIn('id', $hidden)
            ->where(fn ($w) => $w->whereHas('student')->orWhereHas('staffProfile'))
            ->when($type === 'students', fn ($w) => $w->whereHas('student'))
            ->when($type === 'staff', fn ($w) => $w->whereHas('staffProfile')->whereDoesntHave('student'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")
                ->orWhereHas('student', fn ($s) => $s->where('matric_number', 'like', "%{$q}%")->orWhere('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%"))))
            ->orderBy('name')->limit(min(50, $limit))->get()
            ->map(fn ($u) => $this->card($u, $viewer));
    }

    /** People who share the viewer's department or one of their courses (shown first in the directory). */
    public function areMates(User $a, User $b): bool
    {
        if ($a->departmentId() && $a->departmentId() === $b->departmentId()) {
            return true;
        }
        if ($a->student && $b->student) {
            $mine = CourseRegistration::where('student_id', $a->student->id)->pluck('course_id');

            return CourseRegistration::where('student_id', $b->student->id)->whereIn('course_id', $mine)->exists();
        }

        return ChatMember::where('user_id', $a->id)->whereIn('chat_conversation_id', ChatMember::where('user_id', $b->id)->select('chat_conversation_id'))
            ->whereHas('conversation', fn ($q) => $q->where('type', 'course'))->exists();
    }

    /** What the viewer may see of where the person lives, or null. */
    public function lodging(User $person, User $viewer): ?array
    {
        if (! $this->settings->feature('lodging') || ! $person->student) {
            return null;
        }
        $share = $person->share_lodging ?? 'nobody';
        $self = $person->id === $viewer->id;
        if (! $self && ($share === 'nobody' || ($share === 'mates' && ! $this->areMates($person, $viewer)))) {
            return null;
        }
        $session = AcademicSession::current();
        $bed = $session ? BedAllocation::with('room.hostel')->where('student_id', $person->student->id)->where('academic_session_id', $session->id)->holding()->latest('id')->first() : null;
        if (! $bed && blank($person->lodging_note)) {
            return null;
        }

        return ['hostel' => $bed?->room?->hostel?->name, 'room' => $bed?->room?->label(), 'place' => $bed?->place(), 'note' => $person->lodging_note, 'shared_with' => $share];
    }

    public function card(User $u, User $viewer, bool $full = false): array
    {
        $s = $u->student;
        $out = [
            'id' => $u->id, 'name' => $u->name, 'photo_url' => $u->photoUrl(), 'type' => $s ? 'student' : 'staff',
            'headline' => $s ? trim(($s->programme?->name ?? '') . ($s->current_level ? ' · ' . $s->current_level . ' level' : ''), ' ·')
                : trim(($u->staffProfile?->designation ?? 'Staff') . ($u->staffProfile?->department ? ' · ' . $u->staffProfile->department->name : ''), ' ·'),
            'bio' => $u->bio,
        ];
        if ($full) {
            $out += [
                'department' => $s?->programme?->department?->name ?? $u->staffProfile?->department?->name,
                'matric_number' => $u->id === $viewer->id ? $s?->matric_number : null,
                'lodging' => $this->lodging($u, $viewer),
                'can_message' => $this->chat->cannotMessage($viewer, $u) === null,
                'blocked' => UserBlock::where('user_id', $viewer->id)->where('blocked_user_id', $u->id)->exists(),
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /** The person's own privacy and profile choices. */
    public function updateProfile(User $user, array $d): User
    {
        $fields = [];
        if (array_key_exists('bio', $d)) {
            $fields['bio'] = $d['bio'] !== null ? mb_substr(trim((string) $d['bio']), 0, 160) : null;
        }
        if (array_key_exists('share_lodging', $d)) {
            if (! isset(User::SHARE_LODGING[$d['share_lodging']])) {
                throw new RuntimeException('Choose who can see where you live.');
            }
            $fields['share_lodging'] = $d['share_lodging'];
        }
        if (array_key_exists('lodging_note', $d)) {
            $fields['lodging_note'] = $d['lodging_note'] !== null ? mb_substr(trim((string) $d['lodging_note']), 0, 200) : null;
        }
        $user->update($fields);

        return $user;
    }

    /** The profile picture people see in the app (a student's official photo for the ID card is not changed). */
    public function setPhoto(User $user, ?UploadedFile $file): User
    {
        if ($file && (! str_starts_with((string) $file->getMimeType(), 'image/') || $file->getSize() > 4 * 1024 * 1024)) {
            throw new RuntimeException('The picture must be an image of up to 4 MB.');
        }
        $old = $user->avatar;
        $name = $file ? basename($file->store('avatars', 'public')) : null;
        $user->update(['avatar' => $name]);
        if ($old && $old !== $name) {
            Storage::disk('public')->delete('avatars/' . $old);
        }

        return $user;
    }

    public function block(User $user, User $other, bool $block): void
    {
        if ($user->id === $other->id) {
            throw new RuntimeException('That is you.');
        }
        if ($block) {
            UserBlock::firstOrCreate(['user_id' => $user->id, 'blocked_user_id' => $other->id]);
        } else {
            UserBlock::where('user_id', $user->id)->where('blocked_user_id', $other->id)->delete();
        }
    }
}
