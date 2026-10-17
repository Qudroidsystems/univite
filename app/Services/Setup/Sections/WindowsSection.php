<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Services\Setup\SetupSection;

/** Each semester's registration opening and closing dates, matched by the semester's number. */
class WindowsSection implements SetupSection
{
    public function key(): string
    {
        return 'windows';
    }

    public function label(): string
    {
        return 'Registration windows';
    }

    public function about(): string
    {
        return 'When course registration opens and closes in each semester of the session.';
    }

    public function perSession(): bool
    {
        return true;
    }

    public function capture(?AcademicSession $session): array
    {
        return $session ? $session->semesters()->orderBy('sort_order')->get()->mapWithKeys(fn ($s) => [$s->sort_order => [
            'name' => $s->name, 'opens' => $s->getRawOriginal('registration_opens_at'), 'closes' => $s->getRawOriginal('registration_closes_at'),
        ]])->all() : [];
    }

    public function count(array $data): int
    {
        return count(array_filter($data, fn ($w) => $w['opens'] || $w['closes']));
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        $updated = 0;
        $kept = [];
        $semesters = $session ? $session->semesters()->get()->keyBy('sort_order') : collect();
        foreach ($data as $order => $w) {
            $s = $semesters->get((int) $order);
            if (! $s) {
                $kept[] = "{$w['name']} no longer exists in {$session?->name}, so its window was not restored.";
                continue;
            }
            if ($s->getRawOriginal('registration_opens_at') === $w['opens'] && $s->getRawOriginal('registration_closes_at') === $w['closes']) {
                continue;
            }
            $updated++;
            if ($apply) {
                $s->setRawAttributes(array_merge($s->getAttributes(), ['registration_opens_at' => $w['opens'], 'registration_closes_at' => $w['closes']]));
                $s->save();
            }
        }

        return new RestorePlan(0, $updated, 0, $kept);
    }
}
