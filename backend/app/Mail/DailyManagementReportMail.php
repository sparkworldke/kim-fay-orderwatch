<?php

namespace App\Mail;

use App\Models\DailyReportConfig;
use App\Support\FrontendUrl;
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
        $orders = $this->payload['orders'] ?? [];
        $fill = $this->payload['fill_rate'] ?? $this->payload['fill_rate_backorders'] ?? [];
        $backorders = $this->payload['backorders'] ?? [];
        $revenue = $this->payload['revenue_split'] ?? [];

        $reportLabel = e($this->payload['report_date_label'] ?? '');
        $weekLabel = e($this->payload['week']['label'] ?? '');
        $generatedAt = e($this->payload['generated_at_display'] ?? '');
        $timezone = e($this->payload['timezone'] ?? 'Africa/Nairobi');
        $dashboardUrl = FrontendUrl::path('/app');

        $yesterday = $orders['yesterday'] ?? [];
        $weekTotals = $orders['week_totals'] ?? [];
        $ordersHeader = sprintf(
            '<p style="margin:0 0 6px;font-size:13px;"><strong>Yesterday (%s):</strong> %s orders &bull; %s completed &bull; %s pending approval &bull; %s in shipping</p>
             <p style="margin:0 0 12px;font-size:12px;color:#6b7280;"><strong>Week to date (Mon–Sat):</strong> %s orders &bull; %s completed &bull; %s pending approval &bull; %s in shipping</p>',
            e($yesterday['date_label'] ?? $reportLabel),
            (int) ($yesterday['total_orders'] ?? 0),
            (int) ($yesterday['completed_orders'] ?? 0),
            (int) ($yesterday['pending_approval'] ?? 0),
            (int) ($yesterday['in_shipping'] ?? 0),
            (int) ($weekTotals['total_orders'] ?? 0),
            (int) ($weekTotals['completed_orders'] ?? 0),
            (int) ($weekTotals['pending_approval'] ?? 0),
            (int) ($weekTotals['in_shipping'] ?? 0),
        );

        $dailyRows = '';
        foreach ($orders['daily_table'] ?? [] as $row) {
            $dailyRows .= sprintf(
                '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;">%s</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%d</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%d</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%d</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;text-align:right;">%d</td></tr>',
                e($row['date_label'] ?? ''),
                (int) ($row['total_orders'] ?? 0),
                (int) ($row['completed_orders'] ?? 0),
                (int) ($row['pending_approval'] ?? 0),
                (int) ($row['in_shipping'] ?? 0),
            );
        }

        $reasonRows = '';
        foreach ($backorders['top_reasons'] ?? $fill['top_reasons'] ?? [] as $reason) {
            $reasonRows .= sprintf(
                '<tr><td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;">%s</td><td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;text-align:right;">%d</td><td style="padding:6px 10px;border-bottom:1px solid #f3f4f6;text-align:right;">KES %s</td></tr>',
                e((string) ($reason['reason_label'] ?? $reason['reason_code'] ?? 'Unknown')),
                (int) ($reason['line_count'] ?? 0),
                number_format((float) ($reason['revenue_at_risk'] ?? 0), 0),
            );
        }

        // #4/ hide it for now - 4. Nairobi & Mombasa 24hr SLA — retained for future reactivation
        // $nairobi = $sla['nairobi'] ?? [];
        // $mombasa = $sla['mombasa'] ?? [];
        // $slaHtml = sprintf(
        //     '<p style="margin:0 0 8px;font-size:13px;"><strong>Nairobi (24hr):</strong> %.1f%% not delivered after 24h (%d of %d orders, %d completed, KES %s at risk)</p>
        //      <p style="margin:0;font-size:13px;"><strong>Mombasa (24hr):</strong> %.1f%% not delivered after 24h (%d of %d orders, %d completed, KES %s at risk)</p>',
        //     (float) ($nairobi['delayed_pct'] ?? 0),
        //     (int) ($nairobi['delayed_orders'] ?? 0),
        //     (int) ($nairobi['total_orders'] ?? 0),
        //     (int) ($nairobi['completed_orders'] ?? 0),
        //     number_format((float) ($nairobi['delayed_value'] ?? 0), 0),
        //     (float) ($mombasa['delayed_pct'] ?? 0),
        //     (int) ($mombasa['delayed_orders'] ?? 0),
        //     (int) ($mombasa['total_orders'] ?? 0),
        //     (int) ($mombasa['completed_orders'] ?? 0),
        //     number_format((float) ($mombasa['delayed_value'] ?? 0), 0),
        // );

        $revenueDate = e($revenue['date_label'] ?? $reportLabel);
        $unclassified = (float) ($revenue['unclassified'] ?? 0);
        $unclassifiedHtml = $unclassified > 0
            ? sprintf('<p style="margin:8px 0 0;font-size:12px;color:#6b7280;">Unclassified: KES %s</p>', number_format($unclassified, 0))
            : '';

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
                <div style="font-size:13px;opacity:.85;margin-top:4px;">Executive Exceptions &mdash; {$reportLabel}</div>
            </td></tr>
            <tr><td style="padding:16px 32px 8px;font-size:12px;color:#6b7280;">
                <strong>Report date:</strong> {$reportLabel} (yesterday) &nbsp;&bull;&nbsp; Generated: {$generatedAt} ({$timezone})
            </td></tr>

            <tr><td style="padding:16px 32px 8px;">
                <h2 style="margin:0 0 8px;font-size:15px;color:#111827;">1. Order Exceptions</h2>
                {$ordersHeader}
                <p style="margin:0 0 8px;font-size:11px;color:#6b7280;">Orders received by day (Mon–Sat, Sundays excluded)</p>
                <table width="100%" style="font-size:12px;border-collapse:collapse;margin-top:8px;">
                    <tr style="background:#f9fafb;">
                        <th style="padding:8px 12px;text-align:left;">Date</th>
                        <th style="padding:8px 12px;text-align:right;">Total</th>
                        <th style="padding:8px 12px;text-align:right;">Completed</th>
                        <th style="padding:8px 12px;text-align:right;">Pending Approval</th>
                        <th style="padding:8px 12px;text-align:right;">In Shipping</th>
                    </tr>
                    {$dailyRows}
                </table>
            </td></tr>

            <tr><td style="padding:16px 32px 8px;">
                <h2 style="margin:0 0 8px;font-size:15px;color:#111827;">2. Fill Rate &mdash; {$reportLabel}</h2>
                <p style="margin:0;font-size:13px;">
                    <strong>Fill rate (yesterday):</strong> {$this->formatPct($fill['fill_rate_pct'] ?? null)} &nbsp;&bull;&nbsp;
                    <strong>Orders tracked:</strong> {$this->formatInt($fill['orders_tracked'] ?? 0)} &nbsp;&bull;&nbsp;
                    <strong>Revenue not shipped:</strong> KES {$this->formatKes($fill['revenue_not_shipped'] ?? 0)}
                </p>
            </td></tr>

            <tr><td style="padding:16px 32px 8px;">
                <h2 style="margin:0 0 8px;font-size:15px;color:#111827;">3. Backorders &mdash; {$reportLabel}</h2>
                <p style="margin:0 0 10px;font-size:13px;">
                    <strong>Backorder exposure:</strong> {$this->formatPct($backorders['backorder_exposure_pct'] ?? $fill['backorder_exposure_pct'] ?? null)} &nbsp;&bull;&nbsp;
                    <strong>Revenue at risk:</strong> KES {$this->formatKes($backorders['revenue_at_risk'] ?? $fill['backorder_revenue_at_risk'] ?? 0)}
                </p>
                <h3 style="margin:12px 0 6px;font-size:13px;">Top reasons (coded in Acumatica)</h3>
                <table width="100%" style="font-size:12px;border-collapse:collapse;">{$reasonRows}</table>
            </td></tr>

            <tr><td style="padding:16px 32px 24px;">
                <h2 style="margin:0 0 8px;font-size:15px;color:#111827;">4. Revenue Split ({$revenueDate})</h2>
                <table width="100%" style="font-size:13px;border-collapse:collapse;">
                    <tr><td style="padding:6px 0;border-bottom:1px solid #f3f4f6;">KP (customer class KP*)</td><td style="padding:6px 0;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600;">KES {$this->formatKes($revenue['kp'] ?? 0)}</td></tr>
                    <tr><td style="padding:6px 0;border-bottom:1px solid #f3f4f6;">CS (Consumer Sales)</td><td style="padding:6px 0;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:600;">KES {$this->formatKes($revenue['cs'] ?? 0)}</td></tr>
                    <tr><td style="padding:6px 0;">Total yesterday</td><td style="padding:6px 0;text-align:right;font-weight:600;">KES {$this->formatKes($revenue['total'] ?? 0)}</td></tr>
                </table>
                {$unclassifiedHtml}
            </td></tr>

            <tr><td style="padding:20px 32px;background:#f9fafb;text-align:center;font-size:12px;color:#6b7280;">
                Generated by OrderWatch &bull; <a href="{$dashboardUrl}" style="color:#4f6ef7;">View full dashboard</a>
            </td></tr>
        </table>
        </td></tr></table>
        </body></html>
        HTML;
    }

    private function formatPct(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'N/A';
        }

        return number_format((float) $value, 1).'%';
    }

    private function formatKes(mixed $value): string
    {
        return number_format((float) $value, 0);
    }

    private function formatInt(mixed $value): string
    {
        return number_format((int) $value);
    }
}
