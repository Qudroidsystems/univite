<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiClass;
use App\Data\Api\ApiPaper;
use App\Data\Api\ApiUser;
use App\Models\AcademicSession;
use App\Services\RegistrationService;
use App\Services\ResultService;
use App\Services\Schedule\MySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's home screen in one call. */
class DashboardController extends ApiController
{
    public function __construct(private ResultService $results, private MySchedule $schedule, private RegistrationService $registration, private \App\Services\Fees\FeeAccess $feeAccess)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = $this->schedule->term();
        $data = [
            'user' => ApiUser::from($user)->toArray(),
            'session' => AcademicSession::current()?->name,
            'semester' => $term->semester?->name ?? null,
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];

        if ($student = $user->student) {
            $transcript = $this->results->transcript($student);
            $invoices = $student->invoices()->where('status', '!=', 'cancelled')->get();
            $ctx = $this->registration->context();
            $form = $ctx ? $student->registrationForms()->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->first() : null;

            $now = now();
            $next = $this->schedule->lectures($student, $term)
                ->sortBy(fn ($e) => ((($e->day - $now->dayOfWeekIso + 7) % 7) * 1440 + (int) substr($e->starts_at, 0, 2) * 60 + (int) substr($e->starts_at, 3, 2))
                    + (($e->day === $now->dayOfWeekIso && substr($e->ends_at, 0, 5) < $now->format('H:i')) ? 7 * 1440 : 0))
                ->first();
            $exams = $this->schedule->exams($student, $term);
            $nextPaper = $exams->papers->first(fn ($rows) => $rows->first()->date && $rows->first()->date->gte(today()));

            $data += [
                'cgpa' => $transcript->cgpa, 'class' => $transcript->class, 'standing' => $transcript->standing,
                'fees_outstanding' => round($invoices->sum(fn ($i) => $i->outstanding), 2),
                // what the fees paid open: registration, examinations, results and the app itself (locked areas carry the reason)
                'fee_access' => $this->feeAccess->standing($student)->toArray(),
                'registration_status' => $form?->status ?? ($ctx ? 'not_started' : null),
                // Failed courses still to be passed, so the app can keep them in view.
                'outstanding' => $this->registration->outstanding($student, true, $ctx)->map(fn ($o) => \App\Data\Api\ApiCourse::outstanding($o))->values()->all(),
                'next_class' => $next ? ApiClass::from($next)->toArray() : null,
                'next_exam' => $nextPaper ? ApiPaper::from($nextPaper, $exams->seats->get($nextPaper->first()->course_id . '|' . $nextPaper->first()->slots[0]))->toArray() : null,
            ];
        }

        return $this->ok($data);
    }
}
