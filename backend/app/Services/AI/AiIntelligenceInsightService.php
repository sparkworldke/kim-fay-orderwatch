<?php

namespace App\Services\AI;

use App\Exceptions\AiGenerationException;
use Throwable;

class AiIntelligenceInsightService
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly AiPromptLogService $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AiGenerationException
     */
    public function generate(array $payload): array
    {
        $system = $this->systemPrompt();
        $user = 'Generate an executive intelligence briefing from this OrderWatch dataset (compact JSON): '
            .json_encode($this->compactPayload($payload), JSON_UNESCAPED_UNICODE);
        $start = microtime(true);

        try {
            $result = $this->llm->chatJson($system, $user);
            $parsed = $this->parse($result['content']);
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->log([
                'prompt' => mb_substr($user, 0, 8000),
                'intent' => 'ai_intelligence_briefing',
                'domains' => ['orders', 'customers'],
                'ai_message' => mb_substr($result['content'], 0, 8000),
                'provider' => $result['provider'],
                'response_time_ms' => $elapsed,
                'status' => 'success',
            ]);

            return array_merge($parsed, [
                'ai_status' => 'success',
                'provider' => $result['provider'],
                'model' => $result['model'],
            ]);
        } catch (AiGenerationException $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);
            $this->logger->log([
                'prompt' => mb_substr($user, 0, 8000),
                'intent' => 'ai_intelligence_briefing',
                'provider' => $e->provider,
                'response_time_ms' => $elapsed,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            if (config('ai.allow_template_fallback')) {
                return array_merge($this->fallback($payload, $e->codeKey === 'AI_KEY_MISSING' ? 'unavailable' : 'failed'), [
                    'ai_error' => $e->getMessage(),
                    'provider' => $e->provider,
                ]);
            }

            throw $e;
        } catch (Throwable $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);
            $this->logger->log([
                'prompt' => mb_substr($user, 0, 8000),
                'intent' => 'ai_intelligence_briefing',
                'response_time_ms' => $elapsed,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            if (config('ai.allow_template_fallback')) {
                return array_merge($this->fallback($payload, 'failed'), [
                    'ai_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }

            throw new AiGenerationException(
                mb_substr($e->getMessage(), 0, 500),
                'AI_PROVIDER_ERROR',
                502,
            );
        }
    }

    /**
     * Compact metrics for the LLM (cost + context limit control).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        $customers = $payload['customers'] ?? [];

        return [
            'period' => $payload['period'] ?? null,
            'comparison_period' => $payload['comparison_period'] ?? null,
            'orders' => $payload['orders'] ?? null,
            'orders_comparison' => $payload['orders_comparison'] ?? null,
            'customers' => [
                'unique_customers' => $customers['unique_customers'] ?? 0,
                'prior_unique_customers' => $customers['prior_unique_customers'] ?? 0,
                'top_customers' => array_slice($customers['top_customers'] ?? [], 0, 8),
                'fastest_growth' => array_slice($customers['fastest_growth'] ?? [], 0, 5),
                'fastest_decline' => array_slice($customers['fastest_decline'] ?? [], 0, 5),
                'went_quiet' => array_slice($customers['went_quiet'] ?? [], 0, 10),
                'new_or_returning' => array_slice($customers['new_or_returning'] ?? [], 0, 8),
            ],
            'projections' => $payload['projections'] ?? null,
            'historical_weekly' => array_slice($payload['historical_weekly'] ?? [], -8),
            'daily_trend_tail' => array_slice($payload['daily_trend'] ?? [], -14),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public function fallback(array $payload, string $status): array
    {
        $orders = $payload['orders'] ?? [];
        $cmp = $payload['orders_comparison']['orders_received'] ?? null;
        $customers = $payload['customers'] ?? [];
        $proj = $payload['projections'] ?? [];

        $volumeDir = $cmp && ($cmp['change_pct'] ?? 0) >= 0 ? 'up' : 'down';

        return [
            'executive_summary' => sprintf(
                'For %s, OrderWatch recorded %d orders worth KES %s. Volume is %s %.1f%% versus the prior period. Completion rate is %.1f%% with KES %s revenue at risk across %d active customer accounts.',
                $payload['period']['label'] ?? 'the selected period',
                $orders['orders_received'] ?? 0,
                number_format($orders['total_value'] ?? 0, 0),
                $volumeDir,
                abs($cmp['change_pct'] ?? 0),
                $orders['completion_rate'] ?? 0,
                number_format($orders['revenue_at_risk'] ?? 0, 0),
                $customers['unique_customers'] ?? 0,
            ),
            'orders' => [
                'summary' => sprintf(
                    'Captured %d of %d orders (%.1f%%). Average order value KES %s.',
                    $orders['orders_captured'] ?? 0,
                    $orders['orders_received'] ?? 0,
                    $orders['completion_rate'] ?? 0,
                    number_format($orders['avg_order_value'] ?? 0, 0),
                ),
                'highlights' => [
                    sprintf('%d orders remain outstanding in the period', $orders['outstanding'] ?? 0),
                    sprintf('Revenue at risk totals KES %s', number_format($orders['revenue_at_risk'] ?? 0, 0)),
                ],
            ],
            'customer_behaviour' => [
                'summary' => sprintf(
                    '%d customers placed orders in this period versus %d in the comparison window.',
                    $customers['unique_customers'] ?? 0,
                    $customers['prior_unique_customers'] ?? 0,
                ),
                'highlights' => array_values(array_filter([
                    ($customers['fastest_growth'][0]['customer_name'] ?? null)
                        ? 'Strongest growth: '.($customers['fastest_growth'][0]['customer_name'] ?? '')
                        : null,
                    ($customers['fastest_decline'][0]['customer_name'] ?? null)
                        ? 'Largest decline: '.($customers['fastest_decline'][0]['customer_name'] ?? '')
                        : null,
                    count($customers['went_quiet'] ?? []) > 0
                        ? count($customers['went_quiet']).' previously active accounts went quiet'
                        : null,
                ])),
            ],
            'predictions' => [
                'summary' => sprintf(
                    'Based on trailing averages and %.1f%% volume momentum, projected next 7 days: ~%d orders / KES %s.',
                    $proj['volume_momentum_pct'] ?? 0,
                    $proj['projected_next_7_days_orders'] ?? 0,
                    number_format($proj['projected_next_7_days_value'] ?? 0, 0),
                ),
                'highlights' => [
                    'Forecast uses historical weekly trend and recent 7-day momentum',
                    sprintf('Average daily run-rate: %s orders / KES %s', $proj['avg_daily_orders'] ?? 0, number_format($proj['avg_daily_value'] ?? 0, 0)),
                ],
            ],
            'actions' => [
                'Prioritise outstanding high-value orders before period close',
                'Review accounts that went quiet versus the prior period',
                'Monitor completion rate if below 85% target',
            ],
            'ai_status' => $status,
            'provider' => null,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an executive intelligence analyst for Kim-Fay OrderWatch (Kenya food distribution). You receive structured JSON with order metrics, customer behaviour, historical weekly trends, and statistical projections.

Return ONLY valid JSON:
{
  "executive_summary": "3-5 sentence board-ready executive summary",
  "orders": { "summary": "2-3 sentences", "highlights": ["point", "point", "point"] },
  "customer_behaviour": { "summary": "2-3 sentences", "highlights": ["point", "point", "point"] },
  "predictions": { "summary": "2-3 sentences on forward outlook", "highlights": ["point", "point", "point"] },
  "actions": ["action 1", "action 2", "action 3"]
}

Rules:
- Use ONLY numbers from the payload. Never invent figures.
- Use KES for currency.
- Predictions must reference projections and historical data provided.
- Be direct, executive-friendly, no markdown.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function parse(string $raw): array
    {
        $clean = trim($raw);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        }

        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            throw new AiGenerationException(
                'AI intelligence response was not valid JSON.',
                'AI_PROVIDER_ERROR',
                502,
            );
        }

        return [
            'executive_summary' => (string) ($decoded['executive_summary'] ?? ''),
            'orders' => [
                'summary' => (string) ($decoded['orders']['summary'] ?? ''),
                'highlights' => array_values($decoded['orders']['highlights'] ?? []),
            ],
            'customer_behaviour' => [
                'summary' => (string) ($decoded['customer_behaviour']['summary'] ?? ''),
                'highlights' => array_values($decoded['customer_behaviour']['highlights'] ?? []),
            ],
            'predictions' => [
                'summary' => (string) ($decoded['predictions']['summary'] ?? ''),
                'highlights' => array_values($decoded['predictions']['highlights'] ?? []),
            ],
            'actions' => array_values($decoded['actions'] ?? []),
        ];
    }
}
