<?php

namespace App\Services\Reports;

use App\Services\Admin\AiConnectorService;
use App\Services\AI\AiPromptLogService;
use Illuminate\Support\Facades\Http;
use Throwable;

class DailyManagementInsightService
{
    public function __construct(
        private readonly AiConnectorService $ai,
        private readonly AiPromptLogService $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{executive_summary: string, performance_commentary: string, improvements: list<string>, top_positive: ?string, top_negative: ?string, ai_status: string}
     */
    public function generate(array $payload, bool $enabled = true): array
    {
        if (! $enabled) {
            return $this->fallbackInsights($payload, 'skipped');
        }

        [$provider, $apiKey] = $this->ai->resolveKey();
        if (! $apiKey) {
            return $this->fallbackInsights($payload, 'unavailable');
        }

        $system = $this->buildSystemPrompt();
        $user = $this->buildUserPrompt($payload);
        $start = microtime(true);

        try {
            $raw = $provider === 'anthropic'
                ? $this->callAnthropic($apiKey, $system, $user)
                : $this->callOpenAi($apiKey, $system, $user);

            $parsed = $this->parseResponse($raw);
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->log([
                'prompt' => $user,
                'intent' => 'daily_management_report',
                'domains' => ['orders', 'matches', 'risk'],
                'formulas_used' => $payload['formulas'] ?? null,
                'db_query_scope' => ['daily_management_report'],
                'ai_message' => $raw,
                'provider' => $provider,
                'response_time_ms' => $elapsed,
                'status' => 'success',
            ]);

            return array_merge($parsed, ['ai_status' => 'success']);
        } catch (Throwable $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->log([
                'prompt' => $user,
                'intent' => 'daily_management_report',
                'domains' => ['orders'],
                'provider' => $provider,
                'response_time_ms' => $elapsed,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $fallback = $this->fallbackInsights($payload, 'failed');
            $fallback['ai_error'] = $e->getMessage();

            return $fallback;
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function fallbackInsights(array $payload, string $status): array
    {
        $y = $payload['yesterday'];
        $cmp = $payload['comparison']['orders_received'] ?? null;
        $direction = $cmp && $cmp['direction'] === 'up' ? 'increased' : ($cmp && $cmp['direction'] === 'down' ? 'decreased' : 'held steady');

        $summary = sprintf(
            'Yesterday OrderWatch recorded %d orders worth KES %s. Volume %s versus the day before. Completion rate was %.1f%% with KES %s still at risk across %d outstanding orders.',
            $y['orders_received'],
            number_format($y['total_order_value'], 0),
            $direction,
            $y['completion_rate'],
            number_format($y['revenue_at_risk'], 0),
            $y['outstanding_orders'],
        );

        $improvements = [];
        if ($y['completion_rate'] < 85) {
            $improvements[] = sprintf('Recover completion rate above 85%% (currently %.1f%%)', $y['completion_rate']);
        }
        if ($y['outstanding_orders'] > 0) {
            $improvements[] = sprintf('Clear %d outstanding orders worth KES %s', $y['outstanding_orders'], number_format($y['revenue_at_risk'], 0));
        }
        if (($payload['risk']['needs_review_emails'] ?? 0) > 0) {
            $improvements[] = sprintf('Resolve %d email/order matching issues awaiting review', $payload['risk']['needs_review_emails']);
        }
        if ($improvements === []) {
            $improvements[] = 'Maintain current capture performance and monitor MTD trend.';
        }

        $topPositive = $payload['customer_highlights']['top_positive']['customer_name'] ?? null;
        $topRisk = $payload['customer_highlights']['top_risk']['customer_name'] ?? null;

        return [
            'executive_summary' => $summary,
            'performance_commentary' => sprintf(
                'MTD completion rate is %.1f%% with %d orders received month-to-date.',
                $payload['mtd']['completion_rate'] ?? 0,
                $payload['mtd']['orders_received'] ?? 0,
            ),
            'improvements' => $improvements,
            'top_positive' => $topPositive,
            'top_negative' => $topRisk,
            'ai_status' => $status,
        ];
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a management reporting assistant for Kim-Fay OrderWatch. You receive structured KPI JSON for yesterday, day-before-yesterday, MTD, comparisons, risk metrics, and customer highlights.

Return ONLY valid JSON with this exact shape:
{
  "executive_summary": "2-4 sentence management summary",
  "performance_commentary": "1-2 sentences on completion/capture efficiency",
  "improvements": ["action 1", "action 2", "action 3"],
  "top_positive": "customer or account driving positive performance",
  "top_negative": "customer or account driving risk"
}

Rules:
- Use ONLY numbers from the provided payload. Never invent figures.
- Use KES for currency.
- Be direct and executive-friendly.
- improvements must be 3-5 concrete bullet-style action strings.
- No markdown, no code fences, JSON only.
PROMPT;
    }

    /** @param  array<string, mixed>  $payload */
    private function buildUserPrompt(array $payload): string
    {
        return 'Generate management insights for this daily report payload: '.json_encode($payload, JSON_PRETTY_PRINT);
    }

    /** @return array{executive_summary: string, performance_commentary: string, improvements: list<string>, top_positive: ?string, top_negative: ?string} */
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
            'executive_summary' => (string) ($decoded['executive_summary'] ?? ''),
            'performance_commentary' => (string) ($decoded['performance_commentary'] ?? ''),
            'improvements' => array_values(array_filter(
                array_map('strval', $decoded['improvements'] ?? []),
                fn (string $item) => $item !== '',
            )),
            'top_positive' => isset($decoded['top_positive']) ? (string) $decoded['top_positive'] : null,
            'top_negative' => isset($decoded['top_negative']) ? (string) $decoded['top_negative'] : null,
        ];
    }

    private function callOpenAi(string $key, string $system, string $user): string
    {
        $response = Http::withToken($key)->timeout(45)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => 1200,
            'response_format' => ['type' => 'json_object'],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI error: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function callAnthropic(string $key, string $system, string $user): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-haiku-4-5-20251001',
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'max_tokens' => 1200,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Anthropic error: '.($response->json('error.message') ?? $response->body()));
        }

        return $response->json('content.0.text') ?? '';
    }
}