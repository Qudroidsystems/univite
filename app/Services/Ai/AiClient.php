<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\User;
use App\Support\MobileAppSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One way to talk to whichever AI service the school chose under Settings → Mobile App (Anthropic Claude, OpenAI or
 * Google Gemini). Callers send a system prompt and a conversation, optionally with a file (a PDF or a photo of notes),
 * and get text back. Each call counts against the person's daily limit.
 */
class AiClient
{
    public function __construct(private MobileAppSettings $settings)
    {
    }

    public function ready(): bool
    {
        return $this->settings->aiProvider() !== null;
    }

    /** Refuse politely once a person has used today's allowance. */
    public function checkAllowance(User $user): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('The study companion is not switched on for your school yet.');
        }
        $used = (int) AiUsage::where('user_id', $user->id)->whereDate('day', today())->value('requests');
        if ($used >= $this->settings->int('ai_daily_limit')) {
            throw new RuntimeException('You have used today\'s study companion allowance. It resets at midnight.');
        }
    }

    public function remaining(User $user): int
    {
        return max(0, $this->settings->int('ai_daily_limit') - (int) AiUsage::where('user_id', $user->id)->whereDate('day', today())->value('requests'));
    }

    /**
     * @param  array<int, array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array{mime: string, data: string}|null  $file  raw bytes of a PDF or image to read along with the first message
     * @param  bool  $limit  false for school work done on someone's behalf (reading an uploaded result): not counted against their daily allowance
     */
    public function chat(User $user, string $system, array $messages, int $maxTokens = 2000, ?array $file = null, bool $limit = true): string
    {
        if ($limit) {
            $this->checkAllowance($user);
        } elseif (! $this->ready()) {
            throw new RuntimeException('No AI service is set up for the school yet.');
        }
        $provider = $this->settings->aiProvider();
        $model = $this->settings->aiModel();
        $key = (string) $this->settings->get('ai_api_key');

        [$text, $in, $out] = match ($provider) {
            'anthropic' => $this->anthropic($key, $model, $system, $messages, $maxTokens, $file),
            'openai' => $this->openai($key, $model, $system, $messages, $maxTokens, $file),
            'gemini' => $this->gemini($key, $model, $system, $messages, $maxTokens, $file),
        };

        $usage = AiUsage::firstOrCreate(['user_id' => $user->id, 'day' => today()->toDateString()]);
        $usage->increment('requests', $limit ? 1 : 0, ['input_tokens' => $usage->input_tokens + $in, 'output_tokens' => $usage->output_tokens + $out]);

        if (trim($text) === '') {
            throw new RuntimeException('The study companion gave an empty answer. Please try again.');
        }

        return $text;
    }

    /** chat() that expects a JSON object back (tolerates ```json fences and words around it). */
    public function json(User $user, string $system, array $messages, int $maxTokens = 4000, ?array $file = null, bool $limit = true): array
    {
        $text = $this->chat($user, $system . "\n\nReply with one JSON object only, no other text.", $messages, $maxTokens, $file, $limit);

        return self::decode($text) ?? throw new RuntimeException('The study companion\'s answer could not be read. Please try again.');
    }

    public static function decode(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)));
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a === false || $b === false || $b < $a) {
            return null;
        }
        $data = json_decode(substr($text, $a, $b - $a + 1), true);

        return is_array($data) ? $data : null;
    }

    private function fail(string $service, $response): never
    {
        $status = $response->status();
        $detail = $response->json('error.message') ?? $response->json('error') ?? '';
        report(new RuntimeException("{$service} answered {$status}: " . (is_string($detail) ? $detail : json_encode($detail))));

        throw new RuntimeException(match (true) {
            $status === 401 || $status === 403 => 'The study companion is not set up correctly (the AI key was refused). Please tell the ICT unit.',
            $status === 429 => 'The study companion is busy right now. Please try again in a minute.',
            $status === 413 || $status === 400 && str_contains(strtolower((string) json_encode($detail)), 'too long') => 'That is too much text for the study companion at once. Try a shorter document or a narrower topic.',
            default => 'The study companion could not answer just now. Please try again shortly.',
        });
    }

    private function anthropic(string $key, string $model, string $system, array $messages, int $max, ?array $file): array
    {
        $msgs = array_values(array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']], $messages));
        if ($file && $msgs) {
            $block = str_starts_with($file['mime'], 'image/')
                ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $file['mime'], 'data' => base64_encode($file['data'])]]
                : ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($file['data'])]];
            $msgs[0]['content'] = [$block, ['type' => 'text', 'text' => $msgs[0]['content']]];
        }
        $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson()->timeout(120)
            ->post('https://api.anthropic.com/v1/messages', ['model' => $model, 'max_tokens' => $max, 'system' => $system, 'messages' => $msgs]);
        if (! $r->successful()) {
            $this->fail('Anthropic', $r);
        }
        $text = collect($r->json('content', []))->where('type', 'text')->pluck('text')->implode('');

        return [$text, (int) $r->json('usage.input_tokens', 0), (int) $r->json('usage.output_tokens', 0)];
    }

    private function openai(string $key, string $model, string $system, array $messages, int $max, ?array $file): array
    {
        $msgs = [['role' => 'system', 'content' => $system]];
        foreach (array_values($messages) as $i => $m) {
            $content = $m['content'];
            if ($i === 0 && $file) {
                $part = str_starts_with($file['mime'], 'image/')
                    ? ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $file['mime'] . ';base64,' . base64_encode($file['data'])]]
                    : ['type' => 'file', 'file' => ['filename' => 'document.pdf', 'file_data' => 'data:application/pdf;base64,' . base64_encode($file['data'])]];
                $content = [$part, ['type' => 'text', 'text' => $m['content']]];
            }
            $msgs[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }
        $r = Http::withToken($key)->acceptJson()->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', ['model' => $model, 'messages' => $msgs, 'max_completion_tokens' => $max]);
        if (! $r->successful()) {
            $this->fail('OpenAI', $r);
        }

        return [(string) $r->json('choices.0.message.content', ''), (int) $r->json('usage.prompt_tokens', 0), (int) $r->json('usage.completion_tokens', 0)];
    }

    private function gemini(string $key, string $model, string $system, array $messages, int $max, ?array $file): array
    {
        $contents = [];
        foreach (array_values($messages) as $i => $m) {
            $parts = [['text' => $m['content']]];
            if ($i === 0 && $file) {
                array_unshift($parts, ['inline_data' => ['mime_type' => $file['mime'], 'data' => base64_encode($file['data'])]]);
            }
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => $parts];
        }
        $r = Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->timeout(120)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent', [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'generationConfig' => ['maxOutputTokens' => $max],
            ]);
        if (! $r->successful()) {
            $this->fail('Gemini', $r);
        }
        $text = collect($r->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode('');

        return [$text, (int) $r->json('usageMetadata.promptTokenCount', 0), (int) $r->json('usageMetadata.candidatesTokenCount', 0)];
    }
}
