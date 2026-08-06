<?php

namespace App\Mail;

use App\Http\Controllers\Api\FolController;
use App\Models\FolRequest;
use App\Models\FolRequestAttachment;
use App\Services\Fol\FolSettingsService;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

class FolRequestMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly FolRequest $folRequest,
        private readonly string $templateKey,
        private readonly string $subjectLine,
        private readonly array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        $settings = app(FolSettingsService::class);

        return new Envelope(
            from: new Address(
                $settings->mailFromAddress(),
                $settings->mailFromName(),
            ),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    /**
     * Format 1 — real MIME attachments on the email.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $request = $this->folRequest->loadMissing('attachments');
        $out = [];

        foreach ($request->attachments as $file) {
            if (! $file instanceof FolRequestAttachment) {
                continue;
            }
            if (! Storage::disk('local')->exists($file->path)) {
                continue;
            }

            $out[] = Attachment::fromStorageDisk('local', $file->path)
                ->as($file->original_name ?: 'attachment')
                ->withMime($file->mime ?: 'application/octet-stream');
        }

        return $out;
    }

    private function buildHtml(): string
    {
        $request = $this->folRequest->loadMissing(['lines', 'attachments', 'approvalActions']);
        $link = e(FrontendUrl::path('/app/kp/fol/'.$request->id));
        $ref = e($request->public_ref);
        $customer = e($request->customer_name);
        $status = e($request->status);
        $reason = nl2br(e($request->reason_text));
        $debt = nl2br(e($request->debt_explanation));
        $comment = isset($this->context['comment']) ? nl2br(e((string) $this->context['comment'])) : null;
        $stage = e((string) ($this->context['stage'] ?? $request->current_stage_key ?? ''));

        $rows = $request->lines->map(function ($line): string {
            $sku = e($line->inventory_id);
            $desc = e($line->product_description ?? '');
            $qty = e((string) $line->qty_requested);
            $prev = e((string) $line->qty_previously_issued);

            return "<tr><td>{$sku}</td><td>{$desc}</td><td>{$qty}</td><td>{$prev}</td></tr>";
        })->implode('');

        // Format 2 — viewable / downloadable signed links in the email body.
        $attachmentItems = $request->attachments->map(function (FolRequestAttachment $file): string {
            $urls = FolController::signedAttachmentUrls($file);
            $name = e($file->original_name ?: 'attachment');
            $sizeKb = $file->size > 0 ? e(number_format($file->size / 1024, 1)).' KB' : '';
            $view = e($urls['view_url']);
            $download = e($urls['download_url']);

            return <<<LI
            <li style="margin:8px 0;padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;">
                <div style="font-weight:600;color:#111827;">{$name}</div>
                <div style="font-size:12px;color:#6b7280;margin:2px 0 8px;">{$sizeKb}</div>
                <a href="{$view}" style="display:inline-block;margin-right:8px;background:#2563eb;color:#fff;text-decoration:none;padding:6px 10px;border-radius:4px;font-size:12px;">View online</a>
                <a href="{$download}" style="display:inline-block;background:#fff;color:#2563eb;border:1px solid #2563eb;text-decoration:none;padding:6px 10px;border-radius:4px;font-size:12px;">Download</a>
            </li>
            LI;
        })->implode('');

        $attachmentsBlock = $attachmentItems !== ''
            ? "<ul style=\"list-style:none;padding:0;margin:0;\">{$attachmentItems}</ul>
               <p style=\"font-size:12px;color:#6b7280;margin-top:10px;\">Files are also attached to this email. Online links stay valid for 14 days.</p>"
            : '<p>No attachments yet.</p>';

        $commentBlock = $comment !== null
            ? "<h3>Approver comment</h3><p>{$comment}</p>"
            : '';

        $soBlock = $this->buildSalesOrderBlock();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <body style="font-family:Arial,Helvetica,sans-serif;background:#f8fafc;margin:0;padding:24px;color:#1f2937;">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr><td style="padding:22px 24px;border-bottom:1px solid #e5e7eb;">
                    <div style="font-size:12px;text-transform:uppercase;color:#6b7280;">{$this->templateKey} {$stage}</div>
                    <h1 style="font-size:20px;margin:6px 0 0;">{$ref} - {$customer}</h1>
                    <p style="margin:6px 0 0;color:#6b7280;">Status: {$status}</p>
                </td></tr>
                <tr><td style="padding:20px 24px;">
                    <p><a href="{$link}" style="background:#2563eb;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px;">Open in Sight</a></p>
                    {$soBlock}
                    <h3>Line summary</h3>
                    <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                        <thead><tr style="background:#f3f4f6;text-align:left;"><th>SKU</th><th>Description</th><th>Qty</th><th>Prior issued</th></tr></thead>
                        <tbody>{$rows}</tbody>
                    </table>
                    <h3>Reason</h3>
                    <p>{$reason}</p>
                    <h3>Debt explanation</h3>
                    <p>{$debt}</p>
                    {$commentBlock}
                    <h3>Attachments</h3>
                    {$attachmentsBlock}
                    <p style="color:#6b7280;font-size:12px;margin-top:24px;">Kim-Fay Sight internal workflow notification.</p>
                </td></tr>
            </table>
        </body>
        </html>
        HTML;
    }

    private function buildSalesOrderBlock(): string
    {
        $orderNbr = $this->context['acumatica_order_nbr'] ?? null;
        $ok = (bool) ($this->context['so_create_ok'] ?? false);
        $error = isset($this->context['so_create_error']) ? (string) $this->context['so_create_error'] : null;
        $skipped = (bool) ($this->context['so_skipped'] ?? false);

        // Also surface SO already linked on the FOL record (for later emails).
        $linked = $this->folRequest->linked_so_order_nbrs;
        if ((! $orderNbr || $orderNbr === '') && is_array($linked) && $linked !== []) {
            $orderNbr = (string) $linked[0];
        }

        if ($orderNbr) {
            $safe = e((string) $orderNbr);

            return <<<HTML
            <div style="margin:0 0 18px;padding:14px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;">
                <div style="font-size:12px;text-transform:uppercase;color:#047857;font-weight:700;letter-spacing:.04em;">Sales Order created in Acumatica</div>
                <div style="font-size:22px;font-weight:700;color:#065f46;margin-top:4px;font-family:ui-monospace,Consolas,monospace;">{$safe}</div>
                <p style="margin:8px 0 0;font-size:13px;color:#065f46;">
                    This FOL was approved by CCO/final stage and a Sales Order was created for the customer and listed items.
                    Use this SO number in Acumatica for fulfilment / invoicing.
                </p>
            </div>
            HTML;
        }

        if ($skipped) {
            return '';
        }

        if ($error) {
            $safeError = e(mb_substr($error, 0, 500));

            return <<<HTML
            <div style="margin:0 0 18px;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
                <div style="font-size:12px;text-transform:uppercase;color:#b91c1c;font-weight:700;letter-spacing:.04em;">Sales Order not created</div>
                <p style="margin:8px 0 0;font-size:13px;color:#7f1d1d;">
                    FOL final approval completed in Sight, but Acumatica SO creation failed. Create/link the SO manually.
                </p>
                <p style="margin:8px 0 0;font-size:12px;color:#991b1b;font-family:ui-monospace,Consolas,monospace;">{$safeError}</p>
            </div>
            HTML;
        }

        if ($ok === false && array_key_exists('so_create_ok', $this->context)) {
            return <<<HTML
            <div style="margin:0 0 18px;padding:14px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
                <div style="font-size:12px;text-transform:uppercase;color:#b45309;font-weight:700;">Sales Order pending</div>
                <p style="margin:8px 0 0;font-size:13px;color:#92400e;">No Acumatica SO number was returned. Please create or link a sales order manually.</p>
            </div>
            HTML;
        }

        return '';
    }
}
