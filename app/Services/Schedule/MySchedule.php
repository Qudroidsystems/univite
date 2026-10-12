<?php

namespace App\Services\Schedule;

use App\Data\Schedule\CurrentTerm;
use App\Data\Schedule\ExamSchedule;
use App\Data\Schedule\TeachingSchedule;
use App\Models\CourseRegistration;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableInvigilator;
use App\Models\User;
use App\Services\SeatingService;
use Illuminate\Support\Collection;

/** What one person sees of the published timetables: a student's classes, papers and seats; staff teaching and duties. Used by the portal and the mobile app. */
class MySchedule
{
    public function term(): CurrentTerm
    {
        $sem = Semester::current();
        $type = $sem ? SemesterType::where('sort_order', $sem->sort_order)->first() : null;

        return new CurrentTerm($sem?->academic_session_id, $type?->id, $sem);
    }

    /** The courses a student takes this semester: their course form, or their curriculum if they have not registered yet. @return int[] */
    public function courses(Student $s, CurrentTerm $term): array
    {
        $ids = CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.student_id', $s->id)->where('f.academic_session_id', $term->sessionId)->where('f.semester_type_id', $term->semesterTypeId)->where('f.status', '!=', 'rejected')
            ->pluck('course_registrations.course_id')->all();
        if (! $ids && $term->semesterTypeId) {
            $order = SemesterType::find($term->semesterTypeId)?->sort_order;
            $ids = ProgrammeCourse::where('programme_id', $s->programme_id)->where('level', $s->current_level)->where('semester_order', $order)->pluck('course_id')->all();
        }

        return $ids;
    }

    public function published(string $kind, CurrentTerm $term): Collection
    {
        return Timetable::where(['kind' => $kind, 'academic_session_id' => $term->sessionId, 'semester_type_id' => $term->semesterTypeId, 'status' => 'published'])->get();
    }

    /** The student's weekly classes, one row per class. */
    public function lectures(Student $student, CurrentTerm $term): Collection
    {
        return TimetableEntry::with('course', 'venue', 'item')->whereIn('timetable_id', $this->published('lecture', $term)->pluck('id'))
            ->whereIn('course_id', $this->courses($student, $term))->orderBy('day')->orderBy('starts_at')->get()->unique('unit_key')->values();
    }

    public function exams(Student $student, CurrentTerm $term): ExamSchedule
    {
        $papers = TimetableEntry::with('course', 'venue')->whereIn('timetable_id', $this->published('exam', $term)->pluck('id'))
            ->whereIn('course_id', $this->courses($student, $term))->orderBy('date')->orderBy('starts_at')->get()->groupBy('unit_key');
        $seats = SeatingService::seatsFor($student)->keyBy(fn ($s) => $s->course_id . '|' . $s->slot);
        $clash = $papers->groupBy(fn ($rows) => $rows->first()->slots[0])->filter(fn ($g) => $g->count() > 1);

        return new ExamSchedule($papers, $seats, $clash);
    }

    public function teaching(User $user, CurrentTerm $term): TeachingSchedule
    {
        $tts = Timetable::where('status', 'published')->where('academic_session_id', $term->sessionId)->where('semester_type_id', $term->semesterTypeId)->get();
        $classes = TimetableEntry::with('course', 'venue', 'item', 'timetable')->whereIn('timetable_id', $tts->where('kind', 'lecture')->pluck('id'))
            ->whereHas('item', fn ($q) => $q->whereJsonContains('staff', $user->id))->orderBy('day')->orderBy('starts_at')->get()->unique('unit_key')->values();
        $papers = TimetableEntry::with('course', 'venue')->whereIn('timetable_id', $tts->where('kind', 'exam')->pluck('id'))
            ->whereHas('item', fn ($q) => $q->whereJsonContains('staff', $user->id))->orderBy('date')->orderBy('starts_at')->get()->groupBy('unit_key');
        $duties = TimetableInvigilator::with('venue', 'timetable')->where('user_id', $user->id)->whereDate('date', '>=', today())
            ->whereHas('timetable', fn ($q) => $q->where('status', 'published'))->orderBy('date')->orderBy('starts_at')->get();

        return new TeachingSchedule($classes, $papers, $duties);
    }
}
