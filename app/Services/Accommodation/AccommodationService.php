<?php

namespace App\Services\Accommodation;

use App\Data\Accommodation\AllocationRun;
use App\Data\Accommodation\HostelInput;
use App\Data\Accommodation\HostelOccupancy;
use App\Data\Accommodation\RoomBatch;
use App\Data\Campus\ChargeLine;
use App\Models\AcademicSession;
use App\Models\AccommodationApplication;
use App\Models\BedAllocation;
use App\Models\Hostel;
use App\Models\HostelRate;
use App\Models\HostelRoom;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\User;
use App\Services\Campus\AudienceResolver;
use App\Services\Campus\StudentCharges;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hostels, rooms and beds; applications and allocation runs; payment deadlines; moves, cancellations and check-in/out.
 * A bed is held by at most one allocation per session (reserved, confirmed or checked in).
 */
class AccommodationService
{
    public function __construct(
        private AccommodationSettings $settings,
        private AudienceResolver $audiences,
        private StudentCharges $charges,
        private Messenger $messenger,
        private OperationAudit $audit,
    ) {
    }

    // ---------------------------------------------------------------- hostels and rooms

    public function saveHostel(?Hostel $hostel, HostelInput $in, ?AcademicSession $session): Hostel
    {
        return DB::transaction(function () use ($hostel, $in, $session) {
            $hostel ??= new Hostel;
            $hostel->fill($in->attributes())->save();
            if ($session && $in->rate !== null) {
                HostelRate::updateOrCreate(['hostel_id' => $hostel->id, 'academic_session_id' => $session->id], ['amount' => $in->rate]);
            }

            return $hostel;
        });
    }

    public function addRooms(Hostel $hostel, RoomBatch $batch): int
    {
        $existing = $hostel->rooms()->where('block', $batch->block)->pluck('name')->all();
        $n = 0;
        foreach ($batch->names() as $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }
            $hostel->rooms()->create(['block' => $batch->block, 'name' => $name, 'floor' => $batch->floor, 'capacity' => $batch->capacity]);
            $n++;
        }

        return $n;
    }

    /** @return Collection<int, HostelOccupancy> */
    public function occupancy(AcademicSession $session): Collection
    {
        $counts = BedAllocation::query()->join('hostel_rooms', 'hostel_rooms.id', '=', 'bed_allocations.hostel_room_id')
            ->where('academic_session_id', $session->id)->whereIn('status', BedAllocation::HOLDING)
            ->selectRaw('hostel_rooms.hostel_id, bed_allocations.status, count(*) as n')->groupBy('hostel_rooms.hostel_id', 'bed_allocations.status')->get()
            ->groupBy('hostel_id');
        $beds = HostelRoom::where('is_active', true)->selectRaw('hostel_id, sum(capacity) as beds')->groupBy('hostel_id')->pluck('beds', 'hostel_id');

        return Hostel::with('rates', 'warden')->orderBy('sort_order')->orderBy('name')->get()->map(function (Hostel $h) use ($counts, $beds, $session) {
            $c = ($counts[$h->id] ?? collect())->pluck('n', 'status');

            return new HostelOccupancy($h, (int) ($beds[$h->id] ?? 0), (int) ($c['reserved'] ?? 0), (int) ($c['confirmed'] ?? 0), (int) ($c['checked_in'] ?? 0), $h->rateFor($session));
        });
    }

    // ---------------------------------------------------------------- eligibility and free beds

    public function isEligible(Student $student, Hostel $hostel): bool
    {
        return $hostel->is_active
            && ($hostel->gender === 'mixed' || $hostel->gender === $student->gender)
            && $this->audiences->includes($hostel->audienceData(), $student->user);
    }

    /** @return Collection<int, Hostel> */
    public function hostelsFor(Student $student): Collection
    {
        return Hostel::with('rates')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->filter(fn ($h) => $this->isEligible($student, $h))->values();
    }

    /** The first free bed in a hostel (or a given room) as [room, bed], or null. */
    public function freeBed(Hostel $hostel, AcademicSession $session, ?HostelRoom $room = null): ?array
    {
        $rooms = $room ? collect([$room]) : $hostel->rooms()->where('is_active', true)->get();
        $taken = BedAllocation::whereIn('hostel_room_id', $rooms->pluck('id'))->where('academic_session_id', $session->id)->holding()
            ->get(['hostel_room_id', 'bed'])->groupBy('hostel_room_id')->map(fn ($g) => $g->pluck('bed')->all());
        foreach ($rooms as $r) {
            for ($b = 1; $b <= $r->capacity; $b++) {
                if (! in_array($b, $taken[$r->id] ?? [], true)) {
                    return [$r, $b];
                }
            }
        }

        return null;
    }

    public function current(Student $student, AcademicSession $session): ?BedAllocation
    {
        return BedAllocation::with('room.hostel', 'invoice')->where('student_id', $student->id)->where('academic_session_id', $session->id)->holding()->latest('id')->first();
    }

    // ---------------------------------------------------------------- applications

    /** @param  int[]  $preferences */
    public function apply(Student $student, AcademicSession $session, array $preferences): AccommodationApplication
    {
        if (! $this->settings->on('applications_open')) {
            throw new RuntimeException('Applications for accommodation are closed.');
        }
        if (! in_array($student->status, StudentStatus::codesWhere('is_enrolled'), true)) {
            throw new RuntimeException('Only enrolled students can apply for accommodation.');
        }
        if ($this->current($student, $session)) {
            throw new RuntimeException('You already have a bed this session.');
        }
        $allowed = $this->hostelsFor($student)->pluck('id')->all();
        $prefs = array_values(array_unique(array_filter(array_map('intval', $preferences), fn ($id) => in_array($id, $allowed, true))));
        $prefs = array_slice($prefs, 0, max(1, $this->settings->int('max_choices')));
        if (! $prefs) {
            throw new RuntimeException('Choose at least one hostel that is open to you.');
        }
        $app = AccommodationApplication::firstOrNew(['student_id' => $student->id, 'academic_session_id' => $session->id]);
        if ($app->exists && ! in_array($app->status, ['pending', 'withdrawn', 'unsuccessful'], true)) {
            throw new RuntimeException('Your application has already been dealt with.');
        }
        if (! $app->exists || $app->status !== 'pending') {
            $app->created_at = now(); // a fresh application joins the back of the queue
        }
        $app->fill(['preferences' => $prefs, 'status' => 'pending'])->save();

        return $app;
    }

    public function withdraw(AccommodationApplication $app): void
    {
        if ($app->status !== 'pending') {
            throw new RuntimeException('Only an application that is still waiting can be withdrawn.');
        }
        $app->update(['status' => 'withdrawn']);
    }

    // ---------------------------------------------------------------- allocating

    /** Give a student a bed. If the hostel charges for the session, the bed is reserved until the invoice is paid. */
    public function allocate(Student $student, AcademicSession $session, HostelRoom $room, ?int $bed, User $by, ?AccommodationApplication $app = null, bool $checkEligibility = true): BedAllocation
    {
        $room->loadMissing('hostel.rates');
        if ($checkEligibility && ! $this->isEligible($student, $room->hostel)) {
            throw new RuntimeException($student->full_name . ' cannot be placed in ' . $room->hostel->name . ' (it is for other students).');
        }
        if ($this->current($student, $session)) {
            throw new RuntimeException($student->full_name . ' already has a bed this session.');
        }

        return DB::transaction(function () use ($student, $session, $room, $bed, $by, $app) {
            HostelRoom::whereKey($room->id)->lockForUpdate()->first();
            $taken = BedAllocation::where('hostel_room_id', $room->id)->where('academic_session_id', $session->id)->holding()->pluck('bed')->all();
            $bed ??= collect(range(1, max(1, $room->capacity)))->first(fn ($b) => ! in_array($b, $taken, true));
            if (! $bed || $bed > $room->capacity || in_array($bed, $taken, true)) {
                throw new RuntimeException($room->hostel->name . ' ' . $room->label() . ($bed ? ', bed ' . $bed . ' is taken.' : ' is full.'));
            }
            $rate = $room->hostel->rateFor($session);
            $allocation = BedAllocation::create([
                'student_id' => $student->id, 'academic_session_id' => $session->id, 'hostel_room_id' => $room->id, 'bed' => $bed,
                'status' => $rate > 0 ? 'reserved' : 'confirmed', 'confirmed_at' => $rate > 0 ? null : now(), 'application_id' => $app?->id, 'allocated_by' => $by->id,
                'reserved_until' => $rate > 0 ? now()->addDays(max(1, $this->settings->int('payment_days')))->endOfDay() : null,
            ]);
            if ($rate > 0) {
                $invoice = $this->charges->charge($student, 'accommodation', 'Accommodation ' . $session->name, [
                    new ChargeLine('Accommodation: ' . $room->hostel->name . ', ' . $room->label() . ', bed ' . $bed . ' (' . $session->name . ')', $rate, $this->settings->int('bill_item_id') ?: null),
                ], $session, $by, $allocation->reserved_until);
                $allocation->update(['invoice_id' => $invoice->id]);
            }
            $app?->update(['status' => 'allocated']);
            AccommodationApplication::where('student_id', $student->id)->where('academic_session_id', $session->id)->where('status', 'pending')->update(['status' => 'allocated']);
            $this->messenger->notify($student->user, 'accommodation', 'You have a hostel place', 'You have been given ' . $room->hostel->name . ', ' . $room->label() . ', bed ' . $bed . ' for ' . $session->name . '.'
                . ($rate > 0 ? ' Pay ₦' . number_format($rate, 2) . ' by ' . $allocation->reserved_until->format('j M Y') . ' or the bed will be given to someone else.' : ''),
                $allocation, route('my.accommodation'), 'success', null);

            return $allocation;
        });
    }

    /** Place every waiting applicant who can be placed, in the order the settings say. */
    public function run(AcademicSession $session, User $by): AllocationRun
    {
        $apps = AccommodationApplication::with('student.user', 'student.programme')->where('academic_session_id', $session->id)->where('status', 'pending')->get();
        $apps = match ($this->settings->get('priority')) {
            'new_first' => $apps->sortBy([fn ($a, $b) => ((int) $a->student->current_level <=> (int) $b->student->current_level), fn ($a, $b) => $a->created_at <=> $b->created_at]),
            'final_first' => $apps->sortBy([fn ($a, $b) => $this->yearsLeft($a->student) <=> $this->yearsLeft($b->student), fn ($a, $b) => $a->created_at <=> $b->created_at]),
            'ballot' => $apps->shuffle(),
            default => $apps->sortBy('created_at'),
        };
        $placed = $failed = $already = 0;
        $op = $this->audit->begin('accommodation_run', 'accommodation', $by, $session->id, null, ['applications' => $apps->count()]);
        foreach ($apps as $app) {
            $student = $app->student;
            if ($this->current($student, $session)) {
                $app->update(['status' => 'allocated']);
                $already++;
                continue;
            }
            $choices = Hostel::with('rates')->whereIn('id', $app->preferences ?? [])->get()->sortBy(fn ($h) => array_search($h->id, $app->preferences, true));
            if ($this->settings->on('any_hostel')) {
                $choices = $choices->concat($this->hostelsFor($student)->whereNotIn('id', $app->preferences ?? []));
            }
            $spot = null;
            foreach ($choices as $hostel) {
                if ($this->isEligible($student, $hostel) && ($spot = $this->freeBed($hostel, $session))) {
                    break;
                }
            }
            if (! $spot) {
                $app->update(['status' => 'unsuccessful']);
                $this->messenger->notify($student->user, 'accommodation', 'No hostel place this time', 'There was no free bed in the hostels open to you for ' . $session->name . '. You can apply again if places come up.', $app, route('my.accommodation'), 'warning', null);
                $failed++;
                continue;
            }
            $allocation = $this->allocate($student, $session, $spot[0], $spot[1], $by, $app);
            $this->audit->record($op, 'bed_allocated', $allocation, null, ['student' => $student->matric_number, 'place' => $allocation->place()]);
            $placed++;
        }
        $this->audit->finish($op, $placed, $failed, $already);

        return new AllocationRun($placed, $failed, $already);
    }

    private function yearsLeft(Student $s): int
    {
        return (int) ($s->programme?->duration_years ?? 4) * 100 - (int) $s->current_level;
    }

    // ---------------------------------------------------------------- payment and deadlines

    /** Called whenever a student's invoices change: a paid accommodation invoice confirms the bed. */
    public function confirmPaid(Student $student): int
    {
        $n = 0;
        BedAllocation::with('invoice', 'room.hostel', 'session')->where('student_id', $student->id)->where('status', 'reserved')->get()->each(function (BedAllocation $a) use (&$n) {
            if ($this->charges->isPaid($a->invoice)) {
                $a->update(['status' => 'confirmed', 'confirmed_at' => now(), 'reserved_until' => null]);
                $n++;
            }
        });

        return $n;
    }

    /** Reservations not paid by their deadline are released; any part-payment keeps the bed for the bursary to sort out. */
    public function releaseExpired(): int
    {
        $n = 0;
        BedAllocation::with('invoice', 'student.user', 'room.hostel', 'session')->where('status', 'reserved')->where('reserved_until', '<', now())->get()
            ->each(function (BedAllocation $a) use (&$n) {
                if ($a->invoice && (float) $a->invoice->amount_paid > 0) {
                    return;
                }
                DB::transaction(function () use ($a) {
                    $this->charges->withdraw($a->invoice);
                    $a->update(['status' => 'released', 'ended_at' => now(), 'end_reason' => 'Not paid by ' . $a->reserved_until->format('j M Y')]);
                });
                $this->messenger->notify($a->student->user, 'accommodation', 'Hostel place released', 'Your bed in ' . $a->room->hostel->name . ' was released because it was not paid for by ' . $a->reserved_until->format('j M Y') . '.', $a, route('my.accommodation'), 'warning', null);
                $n++;
            });

        return $n;
    }

    // ---------------------------------------------------------------- changes

    public function move(BedAllocation $a, HostelRoom $room, ?int $bed, User $by, string $reason): BedAllocation
    {
        if (! $a->holdsBed()) {
            throw new RuntimeException('This allocation no longer holds a bed.');
        }

        return DB::transaction(function () use ($a, $room, $bed, $by, $reason) {
            HostelRoom::whereKey($room->id)->lockForUpdate()->first();
            $taken = BedAllocation::where('hostel_room_id', $room->id)->where('academic_session_id', $a->academic_session_id)->holding()->where('id', '!=', $a->id)->pluck('bed')->all();
            $bed ??= collect(range(1, max(1, $room->capacity)))->first(fn ($b) => ! in_array($b, $taken, true));
            if (! $bed || $bed > $room->capacity || in_array($bed, $taken, true)) {
                throw new RuntimeException($room->label() . ($bed ? ', bed ' . $bed . ' is taken.' : ' is full.'));
            }
            $before = $a->place();
            $a->update(['hostel_room_id' => $room->id, 'bed' => $bed]);
            $a->load('room.hostel');
            $this->audit->note('accommodation_move', 'accommodation', $by, 'moved', $a, ['place' => $before], ['place' => $a->place()], $reason);
            $this->messenger->notify($a->student->user, 'accommodation', 'Your hostel place has changed', 'You have been moved to ' . $a->place() . '.', $a, route('my.accommodation'), 'info', null);

            return $a;
        });
    }

    public function cancel(BedAllocation $a, User $by, string $reason): void
    {
        if (! $a->holdsBed()) {
            throw new RuntimeException('This allocation no longer holds a bed.');
        }
        DB::transaction(function () use ($a, $by, $reason) {
            if ($a->status === 'reserved') {
                $this->charges->withdraw($a->invoice);
            }
            $a->update(['status' => 'cancelled', 'ended_at' => now(), 'end_reason' => $reason]);
            $this->audit->note('accommodation_cancel', 'accommodation', $by, 'cancelled', $a, null, ['student' => $a->student->matric_number, 'place' => $a->place()], $reason);
        });
    }

    public function checkIn(BedAllocation $a, User $by): void
    {
        if ($a->status !== 'confirmed') {
            throw new RuntimeException($a->status === 'reserved' ? 'This bed has not been paid for yet.' : 'Only a confirmed bed can be checked into.');
        }
        $a->update(['status' => 'checked_in', 'checked_in_at' => now(), 'checked_in_by' => $by->id]);
    }

    public function checkOut(BedAllocation $a, User $by): void
    {
        if ($a->status !== 'checked_in') {
            throw new RuntimeException('Only a student who has checked in can check out.');
        }
        $a->update(['status' => 'checked_out', 'checked_out_at' => now(), 'checked_out_by' => $by->id, 'ended_at' => now(), 'end_reason' => 'Checked out']);
    }
}
