<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Employment details used by leave rules and approval routes: department, category, grade, appointment and service. */
class StaffProfile extends Model
{
    protected $fillable = ['user_id', 'staff_number', 'department_id', 'unit', 'designation', 'category', 'appointment_type', 'grade_level', 'first_appointment_date', 'supervisor_id', 'critical_role'];

    protected $casts = ['first_appointment_date' => 'date', 'critical_role' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function serviceMonths(?\Carbon\CarbonInterface $on = null): int
    {
        return $this->first_appointment_date ? (int) $this->first_appointment_date->diffInMonths($on ?? now()) : 0;
    }
}
