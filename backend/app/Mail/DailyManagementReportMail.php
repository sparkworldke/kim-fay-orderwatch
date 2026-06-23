<?php

namespace App\Mail;

use App\Models\DailyReportConfig;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DailyManagementReportMail extends Mailable
{
    /** @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $insights */
    public function __construct(
        private readonly string $subjectLine,
        private readonly array $payload,
        private readonly array $insights,
        private readonly DailyReportConfig $config,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = array_map(
            fn (string $email) => new \Illuminate\Mail\Mailables\Address($email),
            $this->config->replyTo(),
        );

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            replyTo: $replyTo,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    public function attachments(): array
    {
        return [];
    }

    private function buildHtml(): string
    {
        $y = $this->payload['yesterday'] ?? [];
        $mtd = $this->payload['mtd'] ?? [];
        $comparison = $this->payload['comparison'] ?? [];
        $risk = $this->payload['risk'] ?? [];
        $highlights = $this->payload['customer_highlights'] ?? [];
        $formulas = $this->payload['formulas'] ?? [];
        $dashboardUrl = rtrim((string) config('app.url'), '/');

        $executive = e($this->insights['executive_summary'] ?? '');
        $commentary = e($this->insights['performance_commentary'] ?? '');
        $improvements = $this->insights['improvements'] ?? [];

        $reportLabel = e($this->payload['report_date_label'] ?? '');
        $yesterdayDate = e($this->payload['report_date_display'] ?? '');
        $previousDate = e($this->payload['comparison_date_display'] ?? '');
        $mtdLabel = e($this->payload['mtd_period_label'] ?? '');
        $generatedAt = e($this->payload['generated_at_display'] ?? '');
        $timezone = e($this->payload['timezone'] ?? 'Africa/Nairobi');

        $improvementHtml = '';
        foreach ($improvements as $item) {
            $improvementHtml .= '<li style="margin-bottom:6px;">'.e((string) $item).'</li>';
        }

        $comparisonRows = '';
        foreach ([
            'orders_received' => 'Orders Received',
            'total_order_value' => 'Order Value (KES)',
            'orders_completed' => 'Completed Orders',
            'completion_rate' => 'Completion Rate (%)',
            'outstanding_orders' => 'Outstanding Orders',
            'revenue_at_risk' => 'Revenue at Risk (KES)',
            'critical_orders' => 'Critical Orders',
        ] as $key => $label) {
            if (! isset($comparison[$key])) {
                continue;
            }
            $row = $comparison[$key];
            $changeCell = $this->changeCell($key, $row);
            $comparisonRows .= sprintf(
                '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%s</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%s</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%s</td></tr>',
                e($label),
                $this->formatMetric($key, $row['yesterday'] ?? 0),
                $this->formatMetric($key, $row['day_before'] ?? 0),
                $changeCell,
            );
        }

        $topPositive = e($highlights['top_positive']['customer_name'] ?? $this->insights['top_positive'] ?? 'No data');
        $topRisk = e($highlights['top_risk']['customer_name'] ?? $this->insights['top_negative'] ?? 'No data');
        $pendingManualReview = (int) ($risk['pending_manual_review'] ?? 0);
        $unmatchedEmails = (int) ($risk['unmatched_emails'] ?? 0);

        $revenueAtRiskFormula = e($formulas['revenue_at_risk'] ?? 'SUM(order_total) for uncaptured orders');
        $completionFormula = e($formulas['completion_rate'] ?? 'orders_completed / orders_received × 100');
        $criticalFormula = e($formulas['critical_orders'] ?? 'Orders on hold, pending approval, or rejected');

        $mtdRows = $this->kpiRow('Orders Received', (string) ($mtd['orders_received'] ?? 0))
            .$this->kpiRow('Orders Completed', (string) ($mtd['orders_completed'] ?? 0))
            .$this->kpiRow('Completion Rate', ($mtd['completion_rate'] ?? 0).'%')
            .$this->kpiRow('Revenue (KES)', number_format((float) ($mtd['total_order_value'] ?? 0), 0))
            .$this->kpiRow('Revenue at Risk (KES)', number_format((float) ($mtd['revenue_at_risk'] ?? 0), 0))
            .$this->kpiRow('Critical Orders', (string) ($mtd['critical_orders'] ?? 0));

        $yesterdayRows = $this->kpiRow('Orders Received', (string) ($y['orders_received'] ?? 0))
            .$this->kpiRow('Order Value (KES)', number_format((float) ($y['total_order_value'] ?? 0), 0))
            .$this->kpiRow('Completed', (string) ($y['orders_completed'] ?? 0))
            .$this->kpiRow('Completion Rate', ($y['completion_rate'] ?? 0).'%')
            .$this->kpiRow('Outstanding Orders', (string) ($y['outstanding_orders'] ?? 0))
            .$this->kpiRow('Revenue at Risk (KES)', number_format((float) ($y['revenue_at_risk'] ?? 0), 0));

        $glossary = $this->glossaryBlock($revenueAtRiskFormula, $completionFormula, $criticalFormula);
        $legend = $this->legendBlock();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /></head>
        <body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#374151;">
        <table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 0;">
        <tr><td align="center">
        <table width="640" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:640px;width:100%;box-shadow:0 2px 8px rgba(0,0,0,.08);">
            <tr><td style="background:#1a1a2e;padding:24px 32px;color:#fff;">
                <div style="font-size:20px;font-weight:700;">Kim-Fay OrderWatch</div>
                <div style="font-size:13px;opacity:.85;margin-top:4px;">Daily Management Brief &mdash; {$reportLabel}</div>
            </td></tr>
            <tr><td style="padding:20px 32px 8px;font-size:12px;color:#6b7280;line-height:1.6;">
                <strong>Yesterday:</strong> {$yesterdayDate} &nbsp;&bull;&nbsp;
                <strong>Previous Day:</strong> {$previousDate} &nbsp;&bull;&nbsp;
                <strong>MTD:</strong> {$mtdLabel}<br />
                Generated: {$generatedAt} ({$timezone})
            </td></tr>
            {$glossary}
            {$legend}
            <tr><td style="padding:8px 32px 24px;">
                <h2 style="margin:0 0 8px;font-size:15px;color:#111827;">Executive Summary</h2>
                <p style="margin:0;line-height:1.6;font-size:14px;">{$executive}</p>
            </td></tr>
            <tr><td style="padding:0 32px 20px;">
                <h3 style="margin:0 0 4px;font-size:14px;color:#111827;">MTD &mdash; {$mtdLabel}</h3>
                <p style="margin:0 0 10px;font-size:11px;color:#6b7280;">Month-to-date totals up to {$yesterdayDate}</p>
                <table width="100%" style="font-size:13px;border-collapse:collapse;">
                    {$mtdRows}
                </table>
            </td></tr>
            <tr><td style="padding:0 32px 20px;">
                <h3 style="margin:0 0 4px;font-size:14px;color:#111827;">Yesterday &mdash; {$yesterdayDate}</h3>
                <p style="margin:0 0 10px;font-size:11px;color:#6b7280;">Performance for the previous calendar day</p>
                <table width="100%" style="font-size:13px;border-collapse:collapse;">
                    {$yesterdayRows}
                </table>
            </td></tr>
            <tr><td style="padding:0 32px 20px;">
                <h3 style="margin:0 0 4px;font-size:14px;color:#111827;">vs Previous Day &mdash; {$previousDate}</h3>
                <p style="margin:0 0 10px;font-size:11px;color:#6b7280;">{$yesterdayDate} compared with {$previousDate}</p>
                <table width="100%" style="font-size:12px;border-collapse:collapse;">
                    <tr style="background:#f9fafb;">
                        <th style="padding:8px 12px;text-align:left;">Metric</th>
                        <th style="padding:8px 12px;text-align:right;">{$yesterdayDate}</th>
                        <th style="padding:8px 12px;text-align:right;">{$previousDate}</th>
                        <th style="padding:8px 12px;text-align:right;">Change</th>
                    </tr>
                    {$comparisonRows}
                </table>
            </td></tr>
            <tr><td style="padding:0 32px 20px;">
                <h3 style="margin:0 0 8px;font-size:14px;">Operational Efficiency</h3>
                <p style="margin:0 0 8px;font-size:13px;line-height:1.5;">{$commentary}</p>
                <p style="margin:0;font-size:12px;color:#6b7280;">Pending manual review: {$pendingManualReview} &bull; Unmatched emails yesterday: {$unmatchedEmails}</p>
            </td></tr>
            <tr><td style="padding:0 32px 20px;">
                <h3 style="margin:0 0 8px;font-size:14px;">Account Highlights</h3>
                <p style="margin:0;font-size:13px;"><strong>Top performer:</strong> {$topPositive}</p>
                <p style="margin:6px 0 0;font-size:13px;"><strong>Highest risk:</strong> {$topRisk}</p>
            </td></tr>
            <tr><td style="padding:0 32px 28px;">
                <h3 style="margin:0 0 10px;font-size:14px;">What Needs Improvement Today</h3>
                <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.5;">{$improvementHtml}</ul>
            </td></tr>
            <tr><td style="padding:20px 32px;background:#f9fafb;text-align:center;font-size:12px;color:#6b7280;">
                Generated by OrderWatch &bull; <a href="{$dashboardUrl}" style="color:#4f6ef7;">View full dashboard</a>
            </td></tr>
        </table>
        </td></tr></table>
        </body></html>
        HTML;
    }

    private function glossaryBlock(string $revenueFormula, string $completionFormula, string $criticalFormula): string
    {
        $rows = [
            ['MTD', 'Month-to-Date — cumulative totals from the 1st of the month up to yesterday.'],
            ['Orders Received', 'All sales orders recorded on the reporting day.'],
            ['Orders Completed', 'Orders captured with status Completed, Shipping, or Back Order.'],
            ['Completion Rate', $completionFormula],
            ['Outstanding Orders', 'Orders received but not yet completed or captured.'],
            ['Revenue at Risk', $revenueFormula],
            ['Critical Orders', $criticalFormula],
        ];

        $html = '';
        foreach ($rows as [$term, $definition]) {
            $html .= '<tr><td style="padding:5px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;vertical-align:top;width:38%;">'.e($term).'</td>'
                .'<td style="padding:5px 8px;border-bottom:1px solid #e5e7eb;font-size:11px;color:#4b5563;">'.e($definition).'</td></tr>';
        }

        return <<<HTML
        <tr><td style="padding:12px 32px 0;">
            <div style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
                <div style="background:#f3f4f6;padding:8px 12px;font-size:12px;font-weight:700;color:#374151;">Metrics Glossary</div>
                <table width="100%" style="font-size:12px;border-collapse:collapse;">{$html}</table>
            </div>
        </td></tr>
        HTML;
    }

    private function legendBlock(): string
    {
        return <<<'HTML'
        <tr><td style="padding:12px 32px 0;">
            <div style="border:1px solid #e5e7eb;border-radius:6px;padding:10px 12px;font-size:11px;color:#4b5563;">
                <strong style="color:#374151;">Change indicators:</strong>
                <span style="display:inline-block;margin:4px 10px 0 0;padding:2px 8px;border-radius:4px;background:#dcfce7;color:#15803d;font-weight:600;">&#9650; Green &mdash; Improvement</span>
                <span style="display:inline-block;margin:4px 10px 0 0;padding:2px 8px;border-radius:4px;background:#fef3c7;color:#b45309;font-weight:600;">&#9644; Amber &mdash; Stagnant</span>
                <span style="display:inline-block;margin:4px 0 0;padding:2px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-weight:600;">&#9660; Red &mdash; Decline</span>
            </div>
        </td></tr>
        HTML;
    }

    /** @param  array<string, mixed>  $row */
    private function changeCell(string $key, array $row): string
    {
        $sentiment = $row['sentiment'] ?? 'stagnant';
        $styles = match ($sentiment) {
            'improvement' => ['bg' => '#dcfce7', 'color' => '#15803d', 'arrow' => '&#9650;'],
            'decline' => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'arrow' => '&#9660;'],
            default => ['bg' => '#fef3c7', 'color' => '#b45309', 'arrow' => '&#9644;'],
        };

        $value = $this->formatMetric($key, $row['absolute_change'] ?? 0, true);
        $percent = isset($row['percent_change']) ? ' ('.($row['percent_change'] > 0 ? '+' : '').$row['percent_change'].'%)' : '';

        return sprintf(
            '<span style="display:inline-block;padding:3px 8px;border-radius:4px;background:%s;color:%s;font-weight:600;white-space:nowrap;">%s %s%s</span>',
            $styles['bg'],
            $styles['color'],
            $value,
            $styles['arrow'],
            e($percent),
        );
    }

    private function kpiRow(string $label, string $value): string
    {
        return '<tr><td style="padding:6px 0;border-bottom:1px solid #f3f4f6;">'.e($label).'</td><td style="padding:6px 0;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600;">'.e($value).'</td></tr>';
    }

    private function formatMetric(string $key, mixed $value, bool $signed = false): string
    {
        if (in_array($key, ['total_order_value', 'revenue_at_risk'], true)) {
            $prefix = $signed && $value > 0 ? '+' : '';
            return $prefix.'KES '.number_format((float) $value, 0);
        }
        if ($key === 'completion_rate') {
            $prefix = $signed && $value > 0 ? '+' : '';
            return $prefix.number_format((float) $value, 1).($signed ? ' pts' : '%');
        }
        $prefix = $signed && $value > 0 ? '+' : '';
        return $prefix.(string) $value;
    }
}