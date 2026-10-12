<?php

namespace App\Services\Campus;

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Services\Messenger;
use App\Services\RegistrationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Course materials: lecturers share notes, slides and links with a course; students who have taken or are taking the
 * course download them in the app. Files live on the private disk and are served only to those people.
 */
class MaterialService
{
    public const MAX_MB = 50;

    public function __construct(private RegistrationService $registration, private Messenger $messenger)
    {
    }

    public function canManage(User $user, Course $course): bool
    {
        return $user->can('Manage course materials') || CourseAssignment::where('user_id', $user->id)->where('course_id', $course->id)->exists();
    }

    public function canRead(User $user, Course $course): bool
    {
        if ($this->canManage($user, $course)) {
            return true;
        }

        return $user->student !== null && CourseRegistration::where('student_id', $user->student->id)->where('course_id', $course->id)->exists();
    }

    /** Courses whose materials the person can see: those they teach, and those they are or were registered for. */
    public function courses(User $user): Collection
    {
        $ids = $user->student
            ? CourseRegistration::where('student_id', $user->student->id)->pluck('course_id')
            : CourseAssignment::where('user_id', $user->id)->pluck('course_id');
        $ctx = $this->registration->context();
        $current = $ctx ? ($user->student
            ? CourseRegistration::where('student_id', $user->student->id)->whereHas('form', fn ($q) => $q->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id))->pluck('course_id')
            : CourseAssignment::where('user_id', $user->id)->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->pluck('course_id')) : collect();
        $counts = CourseMaterial::whereIn('course_id', $ids)->groupBy('course_id')->selectRaw('course_id, count(*) n, max(created_at) latest')->get()->keyBy('course_id');

        return Course::whereIn('id', $ids->unique())->orderBy('code')->get()->map(fn ($c) => [
            'id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'current' => $current->contains($c->id),
            'materials' => (int) ($counts[$c->id]->n ?? 0), 'latest_at' => isset($counts[$c->id]) ? \Illuminate\Support\Carbon::parse($counts[$c->id]->latest)->toIso8601String() : null,
            'can_upload' => $this->canManage($user, $c),
        ])->sortByDesc(fn ($c) => [$c['current'] ? 1 : 0, $c['latest_at'] ?? ''])->values();
    }

    public function add(User $user, Course $course, array $d, ?UploadedFile $file): CourseMaterial
    {
        if (! $this->canManage($user, $course)) {
            throw new RuntimeException('Only the course\'s lecturers can add materials.');
        }
        if (! $file && blank($d['link'] ?? null)) {
            throw new RuntimeException('Attach a file or give a link.');
        }
        if ($file && $file->getSize() > self::MAX_MB * 1024 * 1024) {
            throw new RuntimeException('Files can be up to ' . self::MAX_MB . ' MB.');
        }
        $ctx = $this->registration->context();
        $m = CourseMaterial::create([
            'course_id' => $course->id, 'academic_session_id' => $ctx?->session->id, 'user_id' => $user->id,
            'title' => mb_substr(trim((string) $d['title']), 0, 150), 'description' => isset($d['description']) ? mb_substr((string) $d['description'], 0, 1000) : null,
            'link' => $d['link'] ?? null,
            'path' => $file?->store('materials/' . $course->id, 'local'), 'original_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null,
            'mime' => $file?->getMimeType(), 'size' => $file?->getSize(),
        ]);
        $this->tellStudents($course, $m);

        return $m;
    }

    /** Students taking the course this semester hear about new material. */
    private function tellStudents(Course $course, CourseMaterial $m): void
    {
        $ctx = $this->registration->context();
        if (! $ctx) {
            return;
        }
        CourseRegistration::with('student.user')->where('course_id', $course->id)
            ->whereHas('form', fn ($q) => $q->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->whereIn('status', ['submitted', 'approved']))
            ->get()->each(fn ($r) => $r->student?->user && $this->messenger->pushOnly($r->student->user, 'materials', "New in {$course->code}", $m->title, ['course_id' => (string) $course->id]));
    }

    public function remove(CourseMaterial $m, User $user): void
    {
        if ($m->user_id !== $user->id && ! $this->canManage($user, $m->course)) {
            throw new RuntimeException('You cannot remove this.');
        }
        $m->delete();
    }

    public function present(CourseMaterial $m, User $viewer): array
    {
        return [
            'id' => $m->id, 'title' => $m->title, 'description' => $m->description, 'link' => $m->link,
            'file' => $m->path ? ['name' => $m->original_name, 'size' => $m->size, 'mime' => $m->mime, 'url' => route('api.materials.download', $m->id)] : null,
            'by' => $m->user?->name, 'session' => $m->session?->name, 'downloads' => $m->downloads,
            'created_at' => $m->created_at?->toIso8601String(), 'can_delete' => $m->user_id === $viewer->id || $viewer->can('Manage course materials'),
        ];
    }

    public function file(CourseMaterial $m): string
    {
        if (! $m->path || ! Storage::disk('local')->exists($m->path)) {
            abort(404);
        }
        $m->increment('downloads');

        return Storage::disk('local')->path($m->path);
    }
}
