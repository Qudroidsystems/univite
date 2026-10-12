<?php

namespace App\Services\Campus;

use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\FeedComment;
use App\Models\FeedPost;
use App\Models\MarketListing;
use App\Models\User;
use RuntimeException;

/**
 * Reports from the app about messages, listings, posts, comments or people. Moderators (Handle reports) dismiss them
 * or act: hide the listing or post, delete the message or comment. A listing reported by 3 different people is hidden
 * at once until a moderator looks.
 */
class ReportService
{
    public const AUTO_HIDE_AT = 3;

    public function report(User $by, string $type, int $id, string $reason, ?string $details): ContentReport
    {
        if (! isset(ContentReport::TYPES[$type]) || ! isset(ContentReport::REASONS[$reason])) {
            throw new RuntimeException('Choose what you are reporting and why.');
        }
        $class = ContentReport::TYPES[$type];
        $target = $class::find($id) ?? throw new RuntimeException('That is no longer there.');
        if (($target->user_id ?? null) === $by->id || ($type === 'user' && $target->id === $by->id)) {
            throw new RuntimeException('You cannot report yourself.');
        }
        $report = ContentReport::firstOrCreate(
            ['reportable_type' => $type, 'reportable_id' => $id, 'user_id' => $by->id, 'status' => 'open'],
            ['reason' => $reason, 'details' => $details ? mb_substr($details, 0, 1000) : null],
        );

        if ($type === 'listing' && $target->status === 'active'
            && ContentReport::where('reportable_type', 'listing')->where('reportable_id', $id)->where('status', 'open')->distinct('user_id')->count('user_id') >= self::AUTO_HIDE_AT) {
            $target->update(['status' => 'hidden', 'moderation_note' => 'Hidden after several reports while the school looks at it.']);
        }

        return $report;
    }

    /** $action: dismiss | remove (hide the listing/post, delete the message/comment). Every open report on the same thing is closed. */
    public function handle(ContentReport $report, User $by, string $action, ?string $outcome): void
    {
        if (! in_array($action, ['dismiss', 'remove'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        $target = $report->target();
        if ($action === 'remove' && $target) {
            match (true) {
                $target instanceof MarketListing => $target->update(['status' => 'hidden', 'moderation_note' => $outcome ?: 'Removed after a report.', 'moderated_by' => $by->id]),
                $target instanceof FeedPost => $target->update(['status' => 'hidden']),
                $target instanceof ChatMessage, $target instanceof FeedComment => $target->delete(),
                $target instanceof User => null,   // people are handled by suspending the account in Users
                default => null,
            };
        }
        ContentReport::where('reportable_type', $report->reportable_type)->where('reportable_id', $report->reportable_id)->where('status', 'open')
            ->update(['status' => $action === 'remove' ? 'actioned' : 'dismissed', 'handled_by' => $by->id, 'handled_at' => now(), 'outcome' => $outcome]);
    }
}
