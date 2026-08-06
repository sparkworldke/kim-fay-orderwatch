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
        // Runner uses DailyExecutiveReportService (orders.yesterday). Older management
        // payload used top-level yesterday — support both so fallback never crashes.
        $y = $this->resolveYesterdayMetrics($payload);
        $fill = $payload['fill_rate'] ?? [];
        $backorders = $payload['backorders'] ?? [];
        $revenue = $payload['revenue_split'] ?? [];
        $week = $payload['orders']['week_totals'] ?? [];
        $reportLabel = (string) ($payload['report_date_label'] ?? 'yesterday');

        $totalOrders = (int) ($y['total_orders'] ?? $y['orders_received'] ?? 0);
        $completed = (int) ($y['completed_orders'] ?? $y['orders_captured'] ?? 0);
        $pending = (int) ($y['pending_approval'] ?? 0);
        $shipping = (int) ($y['in_shipping'] ?? 0);
        $completionRate = isset($y['completion_rate'])
            ? (float) $y['completion_rate']
            : ($totalOrders > 0 ? round(($completed / $totalOrders) * 100, 1) : 0.0);
        $revenueAtRisk = (float) ($backorders['revenue_at_risk']
            ?? $y['revenue_at_risk']
            ?? $fill['revenue_not_shipped']
            ?? 0);
        $fillRate = $fill['fill_rate_pct'] ?? null;
        $revenueTotal = (float) ($revenue['total'] ?? $y['total_order_value'] ?? 0);

        $cmp = $payload['comparison']['orders_received'] ?? null;
        $direction = $cmp && ($cmp['direction'] ?? null) === 'up'
            ? 'increased'
            : ($cmp && ($cmp['direction'] ?? null) === 'down' ? 'decreased' : 'held steady');

        if (($payload['report_type'] ?? '') === 'daily_executive_email' || isset($payload['orders']['yesterday'])) {
            $summary = sprintf(
                'On %s OrderWatch recorded %d orders (%d completed, %d pending approval, %d in shipping). Fill rate was %s with KES %s backorder revenue at risk. Revenue split total: KES %s.',
                $reportLabel,
                $totalOrders,
                $completed,
                $pending,
                $shipping,
                $fillRate === null || $fillRate === '' ? 'N/A' : number_format((float) $fillRate, 1).'%',
                number_format($revenueAtRisk, 0),
                number_format($revenueTotal, 0),
            );
            $commentary = sprintf(
                'Week to date: %d orders received, %d completed, %d pending approval, %d in shipping.',
                (int) ($week['total_orders'] ?? 0),
                (int) ($week['completed_orders'] ?? 0),
                (int) ($week['pending_approval'] ?? 0),
                (int) ($week['in_shipping'] ?? 0),
            );
        } else {
            $summary = sprintf(
                'Yesterday OrderWatch recorded %d orders worth KES %s. Volume %s versus the day before. Completion rate was %.1f%% with KES %s still at risk across %d outstanding orders.',
                $totalOrders,
                number_format($revenueTotal, 0),
                $direction,
                $completionRate,
                number_format($revenueAtRisk, 0),
                (int) ($y['outstanding_orders'] ?? 0),
            );
            $commentary = sprintf(
                'MTD completion rate is %.1f%% with %d orders received month-to-date.',
                (float) ($payload['mtd']['completion_rate'] ?? 0),
                (int) ($payload['mtd']['orders_received'] ?? 0),
            );
        }

        $improvements = [];
        if ($pending > 0) {
            $improvements[] = sprintf('Clear %d orders still pending approval from %s', $pending, $reportLabel);
        }
        if ($shipping > 0) {
            $improvements[] = sprintf('Chase %d orders stuck in shipping', $shipping);
        }
        if ($completionRate < 85 && $totalOrders > 0) {
            $improvements[] = sprintf('Recover completion rate above 85%% (currently %.1f%%)', $completionRate);
        }
        if ($revenueAtRisk > 0) {
            $improvements[] = sprintf('Reduce backorder revenue at risk (KES %s)', number_format($revenueAtRisk, 0));
        }
        if (($payload['risk']['needs_review_emails'] ?? 0) > 0) {
            $improvements[] = sprintf('Resolve %d email/order matching issues awaiting review', (int) $payload['risk']['needs_review_emails']);
        }
        if ($improvements === []) {
            $improvements[] = 'Maintain current capture performance and monitor week-to-date exceptions.';
        }

        $topPositive = $payload['customer_highlights']['top_positive']['customer_name'] ?? null;
        $topRisk = $payload['customer_highlights']['top_risk']['customer_name'] ?? null;
        $topReason = $backorders['top_reasons'][0]['reason_label']
            ?? $backorders['top_reasons'][0]['reason_code']
            ?? null;

        return [
            'executive_summary' => $summary,
            'performance_commentary' => $commentary,
            'improvements' => $improvements,
            'top_positive' => $topPositive,
            'top_negative' => $topRisk ?? ($topReason !== null ? (string) $topReason : null),
            'ai_status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveYesterdayMetrics(array $payload): array
    {
        if (isset($payload['orders']['yesterday']) && is_array($payload['orders']['yesterday'])) {
            return $payload['orders']['yesterday'];
        }

        if (isset($payload['yesterday']) && is_array($payload['yesterday'])) {
            return $payload['yesterday'];
        }

        return [];
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a management reporting assistant for Kim-Fay OrderWatch. You receive structured KPI JSON for the daily executive exceptions email (orders for yesterday + week-to-date, fill rate, backorders, revenue split KP/CS). Older management payloads may also include MTD comparisons and risk metrics.

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