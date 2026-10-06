<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Programme extends Model
{
    public const DEGREE_TYPES = [
        'ND' => 'National Diploma (ND)', 'HND' => 'Higher National Diploma (HND)',
        'NCE' => 'NCE', 'B.Sc' => 'B.Sc', 'B.A' => 'B.A', 'B.Eng' => 'B.Eng', 'B.Tech' => 'B.Tech',
        'LL.B' => 'LL.B', 'MBBS' => 'MBBS', 'B.Ed' => 'B.Ed', 'B.Agric' => 'B.Agric',
        'PGD' => 'Postgraduate Diploma', 'M.Sc' => 'M.Sc', 'M.A' => 'M.A', 'M.Eng' => 'M.Eng',
        'MBA' => 'MBA', 'M.Phil' => 'M.Phil', 'PhD' => 'PhD',
    ];

    protected $fillable = [
        'department_id', 'name', 'code', 'degree_type', 'duration_years',
        'min_credit_load', 'max_credit_load', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function curriculum(): HasMany
    {
        return $this->hasMany(ProgrammeCourse::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** Levels this programme runs: 100 .. duration_years * 100. */
    public function levels(): array
    {
        return array_map(fn ($y) => $y * 100, range(1, max(1, (int) $this->duration_years)));
    }
}
