<?php

namespace App\Services\Email;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\OrderMatchRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderMatchingService
{
    public function __construct(
        private readonly PoNumberExtractorService $extractor,
    ) {
    }

    // -------------------------------------------------------------------------
    // Step 1: PO Extraction
    // -------------------------------------------------------------------------

    /**
     * Process all emails that haven't had PO extraction attempted yet.
     * Only processes emails whose sender matches an active EmailImportConfig.
     *
     * @return array{processed: int, extracted: int}
     */
    public function runPoExtraction(): array
    {
        $processed = 0;
        $extracted = 0;

        // Fetch allowed sender patterns once
        $configs = EmailImportConfig::where('is_active', true)->get();
        if ($configs->isEmpty()) {
            return ['processed' => 0, 'extracted' => 0];
        }

        Email::where('po_extraction_attempted', false)
            ->orderByDesc('received_at')
            ->chunk(200, function ($emails) use ($configs, &$processed, &$extracted) {
                foreach ($emails as $email) {
                    // Check sender against configs
                    $config = $configs->first(fn ($c) => $c->matchesSender($email->from_email ?? ''));

                    $email->po_extraction_attempted = true;

                    if (! $config) {
                        $email->save();
                        $processed++;
                        continue;
                    }

                    $result = $this->extractor->extract(
                        senderEmail: $email->from_email ?? '',
                        subject:     $email->subject    ?? '',
                        bodyText:    $email->body_preview,
                    );

                    if ($result) {
                        $email->extracted_po_number       = $result->poNumber;
                        $email->po_extraction_method      = $result->method;
                        $email->po_extraction_confidence  = $result->confidence;
                        $extracted++;
                    }

                    $email->save();
                    $processed++;
                }
            });

        return ['processed' => $processed, 'extracted' => $extracted];
    }

    // -------------------------------------------------------------------------
    // Step 2: Order Matching
    // -------------------------------------------------------------------------

    /**
     * Match extracted PO numbers to Acumatica sales orders.
     *
     * Matching logic:
     *  - Email.extracted_po_number == AcumaticaSalesOrder.customer_order
     *  - 1 match  → matched
     *  - >1 match → duplicate
     *  - 0 match  → missing (PO exists in email but not in Acumatica)
     *
     * Reverse scan:
     *  - SOs with no matched email → flag_source = 'acumatica' (email missing)
     */
    public function runOrderMatching(?int $userId = null): OrderMatchRun
    {
        $run = OrderMatchRun::create([
            'triggered_by_user_id' => $userId,
            'started_at'           => now(),
            'status'               => 'running',
        ]);

        $matched              = 0;
        $unmatched            = 0;
        $duplicate            = 0;
        $missingInAcumatica   = 0;

        try {
            // --- Forward pass: emails → orders ---
            $emails = Email::whereNotNull('extracted_po_number')
                ->whereNull('matched_order_id')
                ->get();

            foreach ($emails as $email) {
                $orders = AcumaticaSalesOrder::where('customer_order', $email->extracted_po_number)->get();

                if ($orders->count() === 1) {
                    $order = $orders->first();
                    $this->linkEmailToOrder($email, $order, 'matched');
                    $matched++;
                } elseif ($orders->count() > 1) {
                    // Flag all matching orders as duplicate
                    foreach ($orders as $order) {
                        $order->update(['match_status' => 'duplicate']);
                    }
                    $email->matched_order_id = $orders->first()->id;
                    $email->save();
                    $duplicate++;
                } else {
                    // PO in email has no matching Acumatica SO
                    $missingInAcumatica++;
                    Log::info('Order matching: PO found in email but not in Acumatica', [
                        'po_number'  => $email->extracted_po_number,
                        'email_from' => $email->from_email,
                    ]);
                }
            }

            // --- Reverse pass: orders with no email ---
            AcumaticaSalesOrder::whereNull('email_received_at')
                ->whereNotIn('match_status', ['matched', 'duplicate'])
                ->whereDate('order_date', '>=', now()->subDays(90)) // Only recent orders
                ->update([
                    'match_status' => 'missing',
                    'flag_source'  => 'email',
                ]);

            // Reset flag on matched orders
            AcumaticaSalesOrder::where('match_status', 'matched')
                ->update(['flag_source' => null]);

            $run->update([
                'ended_at'             => now(),
                'status'               => 'completed',
                'emails_processed'     => $emails->count(),
                'matched'              => $matched,
                'unmatched'            => $unmatched,
                'duplicate'            => $duplicate,
                'missing_in_acumatica' => $missingInAcumatica,
                'summary'              => [
                    'matched'              => $matched,
                    'duplicate'            => $duplicate,
                    'missing_in_acumatica' => $missingInAcumatica,
                    'emails_with_po'       => $emails->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('Order matching run failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }

        return $run->fresh();
    }

    // -------------------------------------------------------------------------
    // Manual PO Override
    // -------------------------------------------------------------------------

    /**
     * Manually assign a PO number to an email and immediately attempt matching.
     */
    public function manualPoOverride(Email $email, string $poNumber): ?AcumaticaSalesOrder
    {
        $poNumber = strtoupper(trim($poNumber));

        $email->update([
            'extracted_po_number'      => $poNumber,
            'po_extraction_method'     => 'manual',
            'po_extraction_confidence' => 100,
            'po_extraction_attempted'  => true,
        ]);

        $order = AcumaticaSalesOrder::where('customer_order', $poNumber)->first();
        if ($order) {
            $this->linkEmailToOrder($email, $order, 'matched');
        }

        return $order;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function linkEmailToOrder(Email $email, AcumaticaSalesOrder $order, string $matchStatus): void
    {
        // Link email → order
        $email->matched_order_id = $order->id;
        $email->save();

        // Enrich order with email metadata
        $order->update([
            'match_status'      => $matchStatus,
            'flag_source'       => null,
            'email_subject'     => $email->subject,
            'email_received_at' => $email->received_at,
        ]);
    }
}
