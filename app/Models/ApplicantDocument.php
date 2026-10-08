<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantDocument extends Model
{
    /** type => [label, rule description] */
    public const TYPES = [
        'passport'          => 'Passport photograph',
        'olevel'            => "O'Level result (scanned)",
        'nce_nd'            => 'NCE / ND result (scanned)',
        'jamb_result'       => 'JAMB result slip',
        'birth_certificate' => 'Birth certificate or declaration of age',
        'state_of_origin'   => 'Certificate of state of origin',
    ];

    protected $fillable = ['applicant_id', 'type', 'path', 'original_name', 'size'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function getLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
