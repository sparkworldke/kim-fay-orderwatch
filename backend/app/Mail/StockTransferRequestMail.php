<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StockTransferRequestMail extends Mailable
{
    /**
     * @param  list<array{
     *   inventory_id: string,
     *   product_name: string,
     *   brand: string,
     *   source_warehouse: string,
     *   quantity: float|int,
     *   sources?: list<array{warehouse_name: string, qty_on_hand: float|int, qty_available: float|int}>
     * }>  $requests
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly array $requests,
        private readonly ?string $senderName = null,
        private readonly ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name', 'Kim-Fay Sight'),
            ),
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
        $count = count($this->requests);
        $skuLabel = $count === 1 ? 'SKU' : 'SKUs';
        $sender = $this->senderName ? e($this->senderName) : 'a Sight user';
        $noteHtml = $this->note !== null && trim($this->note) !== ''
            ? '<p style="margin:0 0 16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:13px;color:#1e3a8a;">'
                .'<strong>Note:</strong> '.nl2br(e(trim($this->note))).'</p>'
            : '';

        $byBrand = [];
        foreach ($this->requests as $row) {
            $brand = trim((string) ($row['brand'] ?? '')) ?: 'Unbranded';
            $byBrand[$brand][] = $row;
        }
        ksort($byBrand, SORT_NATURAL | SORT_FLAG_CASE);

        $sections = '';
        foreach ($byBrand as $brand => $rows) {
            $brandSafe = e($brand);
            $brandCount = count($rows);
            $lineRows = '';
            foreach ($rows as $row) {
                $id = e((string) ($row['inventory_id'] ?? ''));
                $name = e((string) ($row['product_name'] ?? ''));
                $source = e((string) ($row['source_warehouse'] ?? ''));
                $qty = e(number_format((float) ($row['quantity'] ?? 0), 0));

                $sourcesDetail = '';
                $sources = $row['sources'] ?? [];
                if (is_array($sources) && $sources !== []) {
                    $parts = [];
                    foreach ($sources as $src) {
                        $wh = e((string) ($src['warehouse_name'] ?? ''));
                        $onHand = e(number_format((float) ($src['qty_on_hand'] ?? 0), 0));
                        $avail = e(number_format((float) ($src['qty_available'] ?? 0), 0));
                        $parts[] = "{$wh} (on hand {$onHand}, available {$avail})";
                    }
                    $sourcesDetail = '<div style="font-size:11px;color:#6b7280;margin-top:4px;">Also at: '.implode('; ', $parts).'</div>';
                }

                $lineRows .= <<<ROW
                <tr>
                    <td style="padding:10px 12px;border-top:1px solid #e5e7eb;vertical-align:top;">
                        <div style="font-weight:600;color:#111827;">{$name}</div>
                        <div style="font-size:12px;color:#6b7280;font-family:ui-monospace,Consolas,monospace;">{$id}</div>
                        {$sourcesDetail}
                    </td>
                    <td style="padding:10px 12px;border-top:1px solid #e5e7eb;vertical-align:top;font-weight:600;">{$source}</td>
                    <td style="padding:10px 12px;border-top:1px solid #e5e7eb;vertical-align:top;text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">{$qty}</td>
                </tr>
                ROW;
            }

            $sections .= <<<SECTION
            <div style="margin:0 0 20px;">
                <h3 style="margin:0 0 8px;font-size:14px;color:#1e3a8a;">{$brandSafe} <span style="font-weight:400;color:#6b7280;">({$brandCount})</span></h3>
                <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;color:#1d4ed8;">
                            <th style="padding:8px 12px;">Product</th>
                            <th style="padding:8px 12px;">Transfer from</th>
                            <th style="padding:8px 12px;text-align:right;">Qty</th>
                        </tr>
                    </thead>
                    <tbody>{$lineRows}</tbody>
                </table>
            </div>
            SECTION;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <body style="font-family:Arial,Helvetica,sans-serif;background:#f8fafc;margin:0;padding:24px;color:#1f2937;">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr><td style="padding:22px 24px;border-bottom:1px solid #e5e7eb;">
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">FGS stock transfer</div>
                    <h1 style="font-size:20px;margin:6px 0 0;">Transfer Requests — {$count} {$skuLabel}</h1>
                    <p style="margin:8px 0 0;color:#6b7280;font-size:13px;">
                        Sent by {$sender} from Kim-Fay Sight. These SKUs have zero on-hand or available stock in FGS and need stock transfers.
                    </p>
                </td></tr>
                <tr><td style="padding:20px 24px;">
                    {$noteHtml}
                    {$sections}
                    <p style="color:#6b7280;font-size:12px;margin:24px 0 0;">
                        This notification was sent from the Production Stock dashboard. Please arrange warehouse transfers into FGS.
                    </p>
                </td></tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
