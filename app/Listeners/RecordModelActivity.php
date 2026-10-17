<?php

namespace App\Listeners;

use App\Models\RecordActivity;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes a record_activities row when a signed-in person creates, changes or deletes any model. Runs for every model
 * (Eloquent's created/updated/deleted events), except the logs themselves and high-volume personal data such as chat.
 */
class RecordModelActivity
{
    /** Models never logged: the logs, and things written constantly that no table shows. */
    private const SKIP = [
        \App\Models\RecordActivity::class, \App\Models\OperationBatch::class, \App\Models\OperationEvent::class, \App\Models\AiUsage::class,
        \App\Models\DeviceToken::class, \App\Models\ChatMessage::class, \App\Models\ChatMember::class, \App\Models\ChatConversation::class,
        \App\Models\PollVoter::class, \App\Models\PollAnswer::class, \App\Models\OutboundMessage::class, \App\Models\HealthAccessLog::class,
        \App\Models\StudyQuiz::class, \App\Models\StudyDocument::class, \App\Models\StudyPlan::class,
    ];

    /** Field changes that are not worth a history line on their own. */
    private const QUIET = ['updated_at', 'last_seen_at', 'last_login_at', 'remember_token'];

    public function __construct(private Auth $auth, private Request $request)
    {
    }

    /** @param  array<int, Model>  $payload */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        $userId = $this->auth->guard()->id();
        if (! $model instanceof Model || $userId === null || in_array($model::class, self::SKIP, true)) {
            return;
        }
        $action = str_starts_with($event, 'eloquent.created') ? 'created' : (str_starts_with($event, 'eloquent.deleted') ? 'deleted' : 'updated');
        $fields = null;
        if ($action === 'updated') {
            $fields = array_values(array_diff(array_keys($model->getChanges()), self::QUIET));
            if (! $fields) {
                return;
            }
        }
        $parts = [];
        $raw = $model->getAttributes();   // stored columns only: some models have a label() method or accessor
        foreach (['code', 'matric_number', 'staff_number', 'name', 'title', 'label'] as $attr) {
            if (is_scalar($v = $raw[$attr] ?? null) && (string) $v !== '' && count($parts) < 2) {
                $parts[] = (string) $v;
            }
        }
        $label = $parts ? mb_substr(implode(' ', $parts), 0, 190) : null;

        try {
            RecordActivity::create([
                'subject_type' => $model->getMorphClass(), 'subject_id' => (int) $model->getKey(), 'user_id' => $userId,
                'action' => $action, 'fields' => $fields, 'label' => $label, 'ip' => $this->request->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);   // the history is a convenience: never let it stop the save itself
        }
    }
}
