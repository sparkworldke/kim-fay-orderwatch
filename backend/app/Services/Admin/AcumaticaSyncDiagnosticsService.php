<?php

namespace App\Services\Admin;

use App\Models\AcumaticaSyncLog;
use App\Services\AI\AiPromptLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AcumaticaSyncDiagnosticsService
{
    private const LOG_WINDOW = 20;

    public function __construct(
        private readonly AiConnectorService $ai,
        private readonly AiPromptLogService $logger,
    ) {
    }

    /**
     * @return array{summary:string, likely_causes:list<string>, next_steps:list<string>, ai_status:string, ai_error?:string, logs_considered:int}
     */
    public function diagnose(): array
    {
        $logs = AcumaticaSyncLog::query()
            ->orderByDesc('started_at')
            ->limit(self::LOG_WINDOW)
            ->get(['sync_type', 'status', 'trigger_type', 'started_at', 'record_count', 'success_count', 'failed_count', 'error_message']);

        if ($logs->isEmpty()) {
            return $this->fallback($logs, 'unavailable');
        }

        [$provider, $apiKey] = $this->ai->resolveKey(['openai']);
        if ($provider !== 'openai' || ! $apiKey) {
            return $this->fallback($logs, 'unavailable');
        }

        $system = $this->buildSystemPrompt();
        $user = $this->buildUserPrompt($logs);
        $start = microtime(true);

        try {
            $raw = $this->callOpenAi($apiKey, $system, $user);
            $parsed = $this->parseResponse($raw);
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->log([
                'prompt' => $user,
                'intent' => 'acumatica_sync_diagnostics',
                'domains' => ['acumatica_sync'],
                'db_query_scope' => ['acumatica_sync_logs'],
                'ai_message' => $raw,
                'provider' => $provider,
                'response_time_ms' => $elapsed,
                'status' => 'success',
            ]);

            return array_merge($parsed, ['ai_status' => 'success', 'logs_considered' => $logs->count()]);
        } catch (Throwable $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->log([
                'prompt' => $user,
                'intent' => 'acumatica_sync_diagnostics',
                'domains' => ['acumatica_sync'],
                'provider' => $provider,
                'response_time_ms' => $elapsed,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $fallback = $this->fallback($logs, 'failed');
            $fallback['ai_error'] = $e->getMessage();

            return $fallback;
        }
    }

    /** @param Collection<int, AcumaticaSyncLog> $logs */
    private function fallback(Collection $logs, string $status): array
    {
        if ($logs->isEmpty()) {
            return [
                'summary' => 'No sync runs recorded yet — nothing to diagnose.',
                'likely_causes' => [],
                'next_steps' => ['Run a sync from the panel above, then check back here.'],
                'ai_status' => $status,
                'logs_considered' => 0,
            ];
        }

        $failed = $logs->whereIn('status', ['failed', 'stopped']);
        $failureRate = round(($failed->count() / max(1, $logs->count())) * 100);

        $topErrors = $failed
            ->pluck('error_message')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(3)
            ->values();

        $bySyncType = $failed->groupBy('sync_type')->map->count()->sortDesc();

        return [
            'summary' => sprintf(
                '%d of the last %d sync runs failed or were stopped (%d%%).',
                $failed->count(),
                $logs->count(),
                $failureRate,
            ),
            'likely_causes' => $topErrors->isEmpty()
                ? ['No recurring error message found — failures may be one-off or interrupted manually.']
                : $topErrors->map(fn ($msg) => Str::limit((string) $msg, 160))->all(),
            'next_steps' => $bySyncType->isEmpty()
                ? ['No recent failures to act on.']
                : $bySyncType->map(fn ($count, $type) => "Review the {$type} sync — {$count} failure(s) in the last ".self::LOG_WINDOW.' runs.')->values()->all(),
            'ai_status' => $status,
            'logs_considered' => $logs->count(),
        ];
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a systems reliability assistant for an Acumatica ERP data-sync pipeline inside an order-management app called OrderWatch. You will be given the most recent sync runs (customers, sales orders, inventory, backorders, fill-rate, or credit-notes syncs) with their status and any error message. Identify patterns across failures, likely root causes, and concrete next steps an admin should try. Be specific and reference the actual sync_type and error text given rather than generic advice. If everything looks healthy, say so plainly.
Respond with a JSON object with exactly these keys: "summary" (1-2 sentences), "likely_causes" (array of short strings, empty array if none), "next_steps" (array of short actionable strings).
PROMPT;
    }

    /** @param Collection<int, AcumaticaSyncLog> $logs */
    private function buildUserPrompt(Collection $logs): string
    {
        $lines = $logs->map(function (AcumaticaSyncLog $log) {
            return sprintf(
                '- %s | status=%s | trigger=%s | started=%s | records=%d success=%d failed=%d | error=%s',
                $log->sync_type,
                $log->status,
                $log->trigger_type,
                optional($log->started_at)->toDateTimeString() ?? 'unknown',
                $log->record_count,
                $log->success_count,
                $log->failed_count,
                $log->error_message ? Str::limit($log->error_message, 200) : 'none',
            );
        })->implode("\n");

        return "Recent Acumatica sync runs, most recent first:\n{$lines}";
    }

    private function callOpenAi(string $key, string $system, string $user): string
    {
        $response = Http::withToken($key)->timeout(45)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => 800,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI error: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function parseResponse(string $raw): array
    {
        $clean = trim($raw);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        }

        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('AI response was not valid JSON.');
        }

        return [
            'summary' => (string) ($decoded['summary'] ?? ''),
            'likely_causes' => array_values(array_filter(
                array_map('strval', $decoded['likely_causes'] ?? []),
                fn (string $item) => $item !== '',
            )),
            'next_steps' => array_values(array_filter(
                array_map('strval', $decoded['next_steps'] ?? []),
                fn (string $item) => $item !== '',
            )),
        ];
    }
}
