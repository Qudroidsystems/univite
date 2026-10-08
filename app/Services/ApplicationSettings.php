<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/** The admission window the school controls: open/closed, session, deadline, screening fee, instructions. */
class ApplicationSettings
{
    public const DEFAULT_FEE = 2000;
    public const DEFAULT_MIN_UTME = 150;

    public static function isOpen(): bool
    {
        if (Setting::get('application.open', '0') !== '1' || ! static::session()) {
            return false;
        }

        $deadline = static::deadline();

        return ! $deadline || ! now()->startOfDay()->gt($deadline);
    }

    /** Why applications are closed, for the public page (null when open). */
    public static function closedReason(): ?string
    {
        if (static::isOpen()) {
            return null;
        }

        if (Setting::get('application.open', '0') === '1' && static::session() && static::deadline()) {
            return 'The deadline for applications (' . static::deadline()->format('j F Y') . ') has passed.';
        }

        return 'Applications are currently closed. Please check back later.';
    }

    public static function session(): ?AcademicSession
    {
        $id = Setting::get('application.session_id');

        return $id ? AcademicSession::find($id) : null;
    }

    public static function deadline(): ?Carbon
    {
        $value = Setting::get('application.deadline');

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    public static function fee(): float
    {
        return (float) Setting::get('application.fee', (string) self::DEFAULT_FEE);
    }

    public static function minUtmeScore(): int
    {
        return (int) Setting::get('application.min_utme_score', (string) self::DEFAULT_MIN_UTME);
    }

    /** The bill item that is charged as the acceptance fee (null = no acceptance fee). */
    public static function acceptanceItemId(): ?int
    {
        $id = Setting::get('onboarding.acceptance_item_id');

        return $id ? (int) $id : null;
    }

    public static function instructions(): ?string
    {
        return Setting::get('application.instructions');
    }

    public static function paymentInstructions(): ?string
    {
        return Setting::get('application.payment_instructions');
    }
}
