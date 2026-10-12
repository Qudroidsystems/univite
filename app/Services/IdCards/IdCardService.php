<?php

namespace App\Services\IdCards;

use App\Data\Campus\Audience;
use App\Data\Campus\ChargeLine;
use App\Data\IdCards\CardCheck;
use App\Data\IdCards\IssueResult;
use App\Models\AcademicSession;
use App\Models\HealthRecord;
use App\Models\IdCard;
use App\Models\StudentStatus;
use App\Models\User;
use App\Services\Campus\AudienceResolver;
use App\Services\Campus\StudentCharges;
use App\Services\Messenger;
use App\Services\OperationAudit;
use App\Support\Lookups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issuing, replacing, cancelling and checking identity cards. A person has at most one valid card: issuing a new one
 * marks the old one replaced, so scanning the old card says so.
 */
class IdCardService
{
    public function __construct(
        private IdCardSettings $settings,
        private AudienceResolver $audiences,
        private StudentCharges $charges,
        private OperationAudit $audit,
        private Messenger $messenger,
    ) {
    }

    public function current(User $user): ?IdCard
    {
        return IdCard::where('user_id', $user->id)->where('status', 'active')->latest('id')->first();
    }

    public function pendingRequest(User $user): ?IdCard
    {
        return IdCard::where('user_id', $user->id)->where('status', 'requested')->latest('id')->first();
    }

    /** Why this person cannot be given a card yet, or null. */
    public function problem(User $user): ?string
    {
        if ($s = $user->student) {
            return match (true) {
                ! in_array($s->status, StudentStatus::codesWhere('is_enrolled'), true) => 'not an enrolled student',
                ! $s->matric_number => 'no matric number yet',
                ! $s->photo => 'no passport photograph',
                default => null,
            };
        }
        if ($p = $user->staffProfile) {
            return match (true) {
                $p->exit_date !== null => 'has left the institution',
                ! $p->staff_number => 'no staff number',
                ! $user->avatar => 'no passport photograph',
                default => null,
            };
        }

        return 'neither a student nor a member of staff';
    }

    /** The details printed on the card, as they are today. */
    public function snapshot(User $user): array
    {
        $blood = null;
        if ($this->settings->on('show_blood_group') && ($h = HealthRecord::where('user_id', $user->id)->first())) {
            $blood = trim(($h->blood_group ? 'Blood group ' . $h->blood_group : '') . ($h->genotype ? ' · Genotype ' . $h->genotype : ''), ' ·') ?: null;
        }
        if ($s = $user->student) {
            $s->loadMissing('programme.department.faculty');

            return ['type' => 'Student', 'name' => $s->full_name, 'number' => $s->matric_number, 'line1' => $s->programme?->name,
                'line2' => $s->programme?->department?->name, 'line3' => $s->programme?->department?->faculty?->name,
                'gender' => $s->gender ? Lookups::label('gender', $s->gender) : null, 'blood' => $blood, 'photo' => $s->photo];
        }
        $p = $user->staffProfile;

        return ['type' => 'Staff', 'name' => $p->displayName(), 'number' => $p->staff_number, 'line1' => $p->designation,
            'line2' => $p->workplace(), 'line3' => null, 'gender' => $user->gender ? Lookups::label('gender', $user->gender) : null, 'blood' => $blood,
            'photo' => $user->avatar ? 'avatars/' . $user->avatar : null];
    }

    public function expiry(User $user): CarbonImmutable
    {
        if (($s = $user->student) && $this->settings->get('student_validity') === 'course') {
            $s->loadMissing('programme');
            $remaining = max(1, (int) ($s->programme?->duration_years ?? 4) - intdiv((int) $s->current_level, 100) + 1);
            $session = AcademicSession::current();
            $end = $session?->end_date ? CarbonImmutable::parse($session->end_date) : CarbonImmutable::now()->addYear();

            return $end->addYears($remaining - 1);
        }

        return CarbonImmutable::today()->addMonths(max(1, $user->student ? $this->settings->int('student_months') : $this->settings->int('staff_months')));
    }

    /** Issue a card now, replacing any valid card. A pending request, if any, becomes this card. */
    public function issue(User $user, User $by, string $reason = 'first'): IdCard
    {
        if ($why = $this->problem($user)) {
            throw new RuntimeException(($user->student?->full_name ?: $user->name) . ': ' . $why . '.');
        }
        $request = $this->pendingRequest($user);
        if ($request?->invoice && ! $this->charges->isPaid($request->invoice)) {
            throw new RuntimeException('The replacement fee on invoice ' . $request->invoice->reference . ' has not been paid.');
        }

        return DB::transaction(function () use ($user, $by, $reason, $request) {
            $old = IdCard::where('user_id', $user->id)->where('status', 'active')->lockForUpdate()->get();
            foreach ($old as $o) {
                $o->update(['status' => 'replaced']);
            }
            $card = $request ?? new IdCard(['user_id' => $user->id, 'reason' => $old->isNotEmpty() && $reason === 'first' ? 'renewal' : $reason]);
            $card->fill([
                'kind' => $user->student ? 'student' : 'staff', 'status' => 'active', 'token' => $card->token ?? Str::random(32),
                'snapshot' => $this->snapshot($user), 'issued_at' => now(), 'issued_by' => $by->id, 'expires_on' => $this->expiry($user),
            ])->save();
            $card->update(['serial' => sprintf('%s/%s/%06d', $this->settings->prefixText(), now()->format('y'), $card->id)]);

            return $card;
        });
    }

    /** Cards for everyone in a group (students or staff) who can have one; by default only those without a valid card. */
    public function issueFor(Audience $audience, User $by, bool $onlyWithout = true): IssueResult
    {
        $users = User::whereIn('id', $this->audiences->userIds($audience))->with('student.programme', 'staffProfile')->get();
        $ids = $skipped = [];
        $had = 0;
        $op = $this->audit->begin('id_cards_issue', 'id_cards', $by, null, null, ['audience' => $audience->toArray()]);
        foreach ($users as $u) {
            if ($onlyWithout && ($c = $this->current($u)) && ! $c->isExpired()) {
                $had++;
                continue;
            }
            if ($why = $this->problem($u)) {
                $skipped[] = ($u->student?->full_name ?: $u->name) . ' (' . ($u->student?->matric_number ?? $u->staffProfile?->staff_number ?? $u->email) . '): ' . $why;
                continue;
            }
            $card = $this->issue($u, $by);
            $this->audit->record($op, 'card_issued', $card, null, ['serial' => $card->serial, 'holder' => $card->snap('name')]);
            $ids[] = $card->id;
        }
        $this->audit->finish($op, count($ids), 0, count($skipped));

        return new IssueResult($ids, $skipped, $had);
    }

    /** A holder asks for a new card. Students pay the replacement fee (if any) for a lost or damaged card first. */
    public function request(User $user, string $reason, ?string $note): IdCard
    {
        if ($this->pendingRequest($user)) {
            throw new RuntimeException('You already have a request waiting.');
        }
        if ($why = $this->problem($user)) {
            throw new RuntimeException('A card cannot be issued yet: ' . $why . '.');
        }
        $current = $this->current($user);
        if (! $current && $reason !== 'first') {
            $reason = 'first';
        }
        if ($current && $reason === 'first') {
            throw new RuntimeException('You already have a valid card. Choose why you need a new one.');
        }

        return DB::transaction(function () use ($user, $reason, $note) {
            $card = IdCard::create(['user_id' => $user->id, 'kind' => $user->student ? 'student' : 'staff', 'status' => 'requested', 'reason' => $reason,
                'token' => Str::random(32), 'request_note' => $note, 'requested_at' => now()]);
            $fee = $this->settings->float('replacement_fee');
            if ($user->student && $fee > 0 && in_array($reason, ['lost', 'damaged'], true)) {
                $invoice = $this->charges->charge($user->student, 'id_card', 'Identity card replacement', [
                    new ChargeLine('Replacement identity card (' . strtolower(IdCard::REASONS[$reason]) . ')', $fee, $this->settings->int('replacement_item_id') ?: null),
                ]);
                $card->update(['invoice_id' => $invoice->id]);
            }

            return $card;
        });
    }

    public function withdrawRequest(IdCard $card): void
    {
        if ($card->status !== 'requested') {
            throw new RuntimeException('Only a request that has not been issued can be withdrawn.');
        }
        $this->charges->withdraw($card->invoice);
        $card->delete();
    }

    public function cancel(IdCard $card, string $reason, User $by): void
    {
        if (! in_array($card->status, ['active', 'requested'], true)) {
            throw new RuntimeException('This card is already ' . strtolower(IdCard::STATUSES[$card->status]) . '.');
        }
        if ($card->status === 'requested') {
            $this->charges->withdraw($card->invoice);
        }
        $card->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);
        $this->audit->note('id_card_cancelled', 'id_cards', $by, 'cancelled', $card, null, ['serial' => $card->serial, 'holder' => $card->snap('name') ?? $card->user?->name], $reason);
    }

    /** @param  int[]  $ids */
    public function markPrinted(array $ids, User $by): int
    {
        return IdCard::whereIn('id', $ids)->where('status', 'active')->whereNull('printed_at')->update(['printed_at' => now(), 'printed_by' => $by->id]);
    }

    public function markCollected(IdCard $card, User $by): void
    {
        if ($card->status !== 'active') {
            throw new RuntimeException('Only a valid card can be handed over.');
        }
        $card->update(['collected_at' => now(), 'collected_by' => $by->id, 'printed_at' => $card->printed_at ?? now(), 'printed_by' => $card->printed_by ?? $by->id]);
        $this->messenger->notify($card->user, 'id_cards', 'Identity card collected', 'Your identity card ' . $card->serial . ' was handed over on ' . now()->format('j M Y') . '.', $card, route('my.id-card'), 'success', null, ['portal']);
    }

    /** What scanning a card's QR code shows. */
    public function check(string $token): CardCheck
    {
        $card = IdCard::with('user.student', 'user.staffProfile')->where('token', $token)->where('status', '!=', 'requested')->first();
        if (! $card) {
            return new CardCheck(null, false, 'This is not a card issued by the institution.');
        }
        $holderGone = $card->kind === 'student'
            ? ! in_array($card->user?->student?->status, StudentStatus::codesWhere('is_enrolled'), true)
            : $card->user?->staffProfile?->exit_date !== null;

        return match (true) {
            $card->status === 'replaced' => new CardCheck($card, false, 'This card has been replaced by a newer card and is no longer valid.'),
            $card->status === 'cancelled' => new CardCheck($card, false, 'This card has been cancelled and is not valid.'),
            $card->isExpired() => new CardCheck($card, false, 'This card expired on ' . $card->expires_on->format('j F Y') . '.'),
            $holderGone => new CardCheck($card, false, $card->kind === 'student' ? 'The holder is no longer an enrolled student.' : 'The holder no longer works at the institution.'),
            default => new CardCheck($card, true, 'Valid ' . strtolower($card->snap('type') ?? 'identity') . ' card' . ($card->expires_on ? ' until ' . $card->expires_on->format('j F Y') : '') . '.'),
        };
    }
}
