<?php

namespace App\Mail;

use App\Models\FolRequest;
use App\Services\Fol\FolSettingsService;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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

    public function attachments(): array
    {
        return [];
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

        $attachments = $request->attachments->map(fn ($file) => '<li>'.e($file->original_name).'</li>')->implode('');
        $attachments = $attachments !== '' ? "<ul>{$attachments}</ul>" : '<p>No attachments yet.</p>';

        $commentBlock = $comment !== null
            ? "<h3>Approver comment</h3><p>{$comment}</p>"
            : '';

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
                    <p><a href="{$link}" style="background:#2563eb;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px;">Open in OrderWatch</a></p>
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
                    {$attachments}
                    <p style="color:#6b7280;font-size:12px;margin-top:24px;">Kim-Fay OrderWatch internal workflow notification.</p>
                </td></tr>
            </table>
        </body>
        </html>
        HTML;
    }
}
