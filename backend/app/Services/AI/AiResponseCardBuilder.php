<?php

namespace App\Services\AI;

class AiResponseCardBuilder
{
    /**
     * Build insight cards and action items from gathered insight data.
     *
     * @param  array  $insights  Keyed by domain: 'orders', 'emails', 'matches', 'customers', 'cron'
     * @param  string $intent    Classified intent
     * @return array{ cards: array, sources: string[], actions: array }
     */
    public function build(array $insights, string $intent): array
    {
        $cards   = [];
        $sources = array_keys($insights);
        $actions = [];

        if (isset($insights['orders'])) {
            $o = $insights['orders'];
            $cards[] = $this->kpi('Orders Today', $o['total'], 'Sales orders for today');
            $cards[] = $this->kpi('Captured', $o['captured'], "{$o['capture_rate']}% capture rate");
            $cards[] = $this->kpi('Uncaptured', $o['uncaptured'], 'Not yet completed/shipped');

            $revenueAtRisk = $o['revenue_at_risk'];
            if ($revenueAtRisk > 0) {
                $cards[] = $this->risk(
                    'Revenue at Risk',
                    'KES ' . number_format($revenueAtRisk, 0),
                    $revenueAtRisk > 500000 ? 'high' : ($revenueAtRisk > 100000 ? 'medium' : 'low'),
                    "From {$o['uncaptured']} uncaptured orders"
                );
                $actions[] = ['label' => 'View Uncaptured Orders', 'url' => '/app/orders?match_status=unmatched'];
            }

            if (!empty($o['top_customers'])) {
                $top = $o['top_customers'][0];
                $cards[] = $this->customer(
                    'Top Customer Today',
                    $top['customer_name'] ?? 'N/A',
                    'KES ' . number_format($top['total_value'] ?? 0, 0) . ' · ' . ($top['orders'] ?? 0) . ' orders'
                );
            }

            // Today vs yesterday comparison
            $yTotal = $insights['orders']['yesterday']['total'] ?? 0;
            $yValue = $insights['orders']['yesterday']['total_value'] ?? 0;
            if ($yTotal > 0 || $o['total'] > 0) {
                $cards[] = $this->comparison(
                    'Today vs Yesterday',
                    $o['total'],
                    $yTotal,
                    'orders',
                    'KES ' . number_format($o['total_value'], 0),
                    'KES ' . number_format($yValue, 0)
                );
            }
        }

        if (isset($insights['emails'])) {
            $e = $insights['emails'];
            $cards[] = $this->kpi('Emails Received', $e['total_received'], 'Today');
            $cards[] = $this->kpi('With PO Detected', $e['with_po_detected'], 'PO number extracted');

            if ($e['awaiting_review'] > 0) {
                $cards[] = $this->risk(
                    'Awaiting Review',
                    $e['awaiting_review'],
                    $e['awaiting_review'] > 20 ? 'high' : 'medium',
                    'Emails pending manual review'
                );
                $actions[] = ['label' => 'Review Pending Emails', 'url' => '/app/mailbox?review_status=pending'];
            }

            if ($e['all_time_unmatched'] > 0) {
                $cards[] = $this->risk(
                    'Unmatched Emails',
                    $e['all_time_unmatched'],
                    $e['all_time_unmatched'] > 50 ? 'high' : 'medium',
                    'No sales order linked yet'
                );
            }
        }

        if (isset($insights['matches'])) {
            $m = $insights['matches'];
            $atm = $m['all_time_email_match'];
            $cards[] = $this->match(
                'Email Match Status',
                $atm['matched'],
                $atm['matched_with_discrepancies'],
                $atm['needs_review'],
                $atm['unmatched']
            );

            if ($atm['needs_review'] > 0) {
                $actions[] = ['label' => 'Review Match Discrepancies', 'url' => '/app/mailbox?match_classification=needs_review'];
            }
        }

        if (isset($insights['customers'])) {
            $c = $insights['customers'];
            $cards[] = $this->kpi('Active Customers', $c['active_customers'], "of {$c['total_customers']} total");

            if (!empty($c['churn_risk'])) {
                $names = implode(', ', array_column($c['churn_risk'], 'name'));
                $cards[] = $this->risk(
                    'Churn Risk',
                    count($c['churn_risk']) . ' customers',
                    'medium',
                    "No orders in 90 days: {$names}"
                );
            }
        }

        if (isset($insights['cron'])) {
            $cr = $insights['cron'];
            $lh = $cr['last_24h'];
            if ($lh['total_runs'] > 0) {
                $cards[] = $this->kpi(
                    'Cron Runs (24h)',
                    $lh['successful'] . '/' . $lh['total_runs'],
                    "{$lh['failed']} failed · {$lh['emails_processed']} emails processed"
                );
            }
            if ($lh['failed'] > 0) {
                $cards[] = $this->risk('Cron Failures', $lh['failed'], 'high', 'In last 24 hours');
            }
        }

        return compact('cards', 'sources', 'actions');
    }

    // ── Card factories ──────────────────────────────────────────────────────────

    private function kpi(string $title, mixed $value, string $subtitle = ''): array
    {
        return array_filter([
            'type'     => 'kpi',
            'title'    => $title,
            'value'    => $value,
            'subtitle' => $subtitle ?: null,
        ]);
    }

    private function risk(string $title, mixed $value, string $severity, string $subtitle = ''): array
    {
        return array_filter([
            'type'     => 'risk',
            'title'    => $title,
            'value'    => $value,
            'severity' => $severity,
            'subtitle' => $subtitle ?: null,
        ]);
    }

    private function customer(string $title, string $name, string $subtitle = ''): array
    {
        return array_filter([
            'type'     => 'customer',
            'title'    => $title,
            'value'    => $name,
            'subtitle' => $subtitle ?: null,
        ]);
    }

    private function comparison(
        string $title,
        int|float $current,
        int|float $previous,
        string $unit,
        string $currentLabel = '',
        string $previousLabel = ''
    ): array {
        $diff    = $current - $previous;
        $pct     = $previous > 0 ? round(($diff / $previous) * 100, 1) : null;
        $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');

        return array_filter([
            'type'      => 'comparison',
            'title'     => $title,
            'value'     => $currentLabel ?: $current,
            'subtitle'  => "vs " . ($previousLabel ?: $previous) . " {$unit}",
            'trend'     => [
                'direction' => $direction,
                'percent'   => $pct,
                'label'     => $pct !== null ? abs($pct) . '% ' . ($direction === 'up' ? '↑' : ($direction === 'down' ? '↓' : '→')) : null,
            ],
        ]);
    }

    private function match(string $title, int $matched, int $discrepancies, int $needsReview, int $unmatched): array
    {
        return [
            'type'  => 'match',
            'title' => $title,
            'value' => $matched,
            'items' => [
                ['label' => 'Matched',              'value' => $matched],
                ['label' => 'With discrepancies',   'value' => $discrepancies],
                ['label' => 'Needs review',          'value' => $needsReview],
                ['label' => 'Unmatched',             'value' => $unmatched],
            ],
        ];
    }
}
