<?php

namespace App\Data\Legacy;

/** What an import may add to the portal when the old records mention something it does not have yet. */
final readonly class ImportOptions
{
    public const LABELS = [
        'create_sessions' => 'Add sessions the portal does not have yet (old sessions are added as past sessions)',
        'create_courses' => 'Add courses the portal does not have yet (needs a course title and units; the course joins the student\'s department)',
    ];

    public function __construct(public bool $createSessions = true, public bool $createCourses = false)
    {
    }

    public static function fromArray(?array $a): self
    {
        return $a === null ? new self() : new self(! empty($a['create_sessions']), ! empty($a['create_courses']));
    }

    public function toArray(): array
    {
        return ['create_sessions' => $this->createSessions, 'create_courses' => $this->createCourses];
    }
}
