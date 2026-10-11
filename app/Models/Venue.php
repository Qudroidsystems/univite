<?php

namespace App\Models;

use App\Support\Lookups;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A lecture hall, classroom, laboratory, examination hall or other space. Both timetables read venues from here. */
class Venue extends Model
{
    public const STATUSES = ['available' => 'Available', 'reserved' => 'Reserved', 'maintenance' => 'Under maintenance', 'closed' => 'Temporarily closed'];
    public const BADGES = ['available' => 'bg-success-subtle text-success', 'reserved' => 'bg-info-subtle text-info', 'maintenance' => 'bg-warning-subtle text-warning', 'closed' => 'bg-danger-subtle text-danger'];
    public const USES = ['lecture' => 'Lectures', 'practical' => 'Practicals', 'exam' => 'Examinations', 'meeting' => 'Meetings and events'];

    protected $fillable = [
        'code', 'name', 'type', 'campus', 'building', 'floor', 'room_number', 'department_id', 'capacity', 'teaching_capacity', 'exam_capacity',
        'seat_rows', 'seats_per_row', 'blocked_seats', 'accessible_seats', 'uses', 'facilities', 'shared_exams', 'wheelchair_access', 'entry_info',
        'status', 'notes', 'is_active',
    ];

    protected $casts = [
        'blocked_seats' => 'array', 'accessible_seats' => 'array', 'uses' => 'array', 'facilities' => 'array',
        'shared_exams' => 'boolean', 'wheelchair_access' => 'boolean', 'is_active' => 'boolean',
    ];

    protected $attributes = ['status' => 'available'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(VenueClosure::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(VenueIssue::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VenueBooking::class);
    }

    public function getTypeLabelAttribute(): string
    {
        return Lookups::label('venue_type', $this->type);
    }

    public function getLocationAttribute(): string
    {
        return collect([$this->building, $this->floor ? 'Floor ' . $this->floor : null, $this->room_number ? 'Room ' . $this->room_number : null, $this->campus])->filter()->implode(', ');
    }

    public function allows(string $use): bool
    {
        return in_array($use, $this->uses ?: array_keys(self::USES), true);
    }

    public function hasFacilities(array $needed): bool
    {
        return ! array_diff($needed, $this->facilities ?? []);
    }

    /** Is it out of use on this date (status, a closure, or an unresolved fault that blocks it)? Returns the reason. */
    public function blockedReason(?CarbonInterface $date = null): ?string
    {
        if (! $this->is_active) {
            return 'retired from the register';
        }
        if (in_array($this->status, ['maintenance', 'closed'], true)) {
            return strtolower(self::STATUSES[$this->status]);
        }
        $issue = $this->relationLoaded('issues')
            ? $this->issues->first(fn ($i) => $i->severity === 'blocking' && $i->status !== 'resolved')
            : $this->issues()->where('severity', 'blocking')->where('status', '!=', 'resolved')->first();
        if ($issue) {
            return 'fault reported: ' . $issue->title;
        }
        if ($date) {
            $d = $date->toDateString();
            $closure = $this->relationLoaded('closures')
                ? $this->closures->first(fn ($c) => ! $c->lifted_at && $c->starts_on->toDateString() <= $d && $c->ends_on->toDateString() >= $d)
                : $this->closures()->whereNull('lifted_at')->whereDate('starts_on', '<=', $d)->whereDate('ends_on', '>=', $d)->first();
            if ($closure) {
                return 'closed ' . $closure->starts_on->format('j M') . '–' . $closure->ends_on->format('j M') . ': ' . $closure->reason;
            }
        }

        return null;
    }

    /**
     * The examination seat map: every usable seat in order, row by row. Without a drawn map, rows of ten are assumed.
     *
     * @return array<int, array{label: string, row: int, col: int, accessible: bool}>
     */
    public function seatMap(): array
    {
        $perRow = $this->seats_per_row ?: 10;
        $rows = $this->seat_rows ?: (int) ceil(max(1, $this->exam_capacity) / $perRow);
        $blocked = array_flip($this->blocked_seats ?? []);
        $accessible = array_flip($this->accessible_seats ?? []);
        $seats = [];
        for ($r = 1; $r <= $rows; $r++) {
            for ($c = 1; $c <= $perRow; $c++) {
                $label = self::seatLabel($r, $c);
                if (! isset($blocked[$label])) {
                    $seats[] = ['label' => $label, 'row' => $r, 'col' => $c, 'accessible' => isset($accessible[$label])];
                }
            }
        }

        // A drawn map can hold more chairs than may be used for an examination: keep only exam_capacity of them.
        return $this->exam_capacity > 0 ? array_slice($seats, 0, $this->exam_capacity) : $seats;
    }

    public static function seatLabel(int $row, int $col): string
    {
        return sprintf('R%02d-S%02d', $row, $col);
    }
}
