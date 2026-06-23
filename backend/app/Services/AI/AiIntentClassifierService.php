<?php

namespace App\Services\AI;

class AiIntentClassifierService
{
    // Intent → keyword triggers
    private const INTENT_KEYWORDS = [
        'order_summary' => [
            'order', 'orders', 'capture', 'captured', 'uncaptured', 'revenue', 'sales order',
            'today orders', 'daily orders', 'order value', 'order total', 'at risk',
        ],
        'email_summary' => [
            'email', 'emails', 'inbox', 'unmatched email', 'awaiting review',
            'skipped email', 'po email', 'email sync', 'received email',
        ],
        'match_summary' => [
            'match', 'matched', 'matching', 'unmatched', 'discrepancy', 'reconcil',
            'link', 'linked', 'po number', 'purchase order',
        ],
        'customer_summary' => [
            'customer', 'customers', 'client', 'account', 'naivas', 'carrefour',
            'quickmart', 'chandarana', 'declining', 'churn', 'inactive',
        ],
        'cron_summary' => [
            'cron', 'cron job', 'scheduled', 'automation', 'last run', 'job run',
            'sync job', 'auto-match', 'automatch',
        ],
        'comparison' => [
            'compare', 'comparison', ' vs ', 'versus', 'last week', 'last month',
            'yesterday', 'wow', 'mom', 'mtd', 'ytd', 'prior', 'week on week',
            'month on month', 'this week vs', 'trend',
        ],
        'risk_summary' => [
            'risk', 'risky', 'critical', 'attention', 'problem', 'issues', 'alert',
            'warning', 'overdue', 'late', 'sla', 'breach', 'what needs',
        ],
    ];

    // Intent → data domains it requires
    private const INTENT_DOMAINS = [
        'order_summary'   => ['orders'],
        'email_summary'   => ['emails'],
        'match_summary'   => ['emails', 'matches'],
        'customer_summary'=> ['customers', 'orders'],
        'cron_summary'    => ['cron'],
        'comparison'      => ['orders', 'emails', 'matches'],
        'risk_summary'    => ['orders', 'emails', 'matches'],
        'general'         => ['orders', 'emails', 'matches'],
    ];

    /**
     * @return array{ intent: string, domains: string[] }
     */
    public function classify(string $prompt): array
    {
        $lower  = mb_strtolower($prompt);
        $scores = [];

        foreach (self::INTENT_KEYWORDS as $intent => $keywords) {
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scores[$intent] = $score;
            }
        }

        if (empty($scores)) {
            $intent = 'general';
        } else {
            arsort($scores);
            $intent = array_key_first($scores);
        }

        return [
            'intent'  => $intent,
            'domains' => self::INTENT_DOMAINS[$intent] ?? ['orders'],
        ];
    }
}
