<?php

namespace App\Services\OrderMatch;

use App\Models\Email;
use App\Services\Email\OrderMatchingService;

class OrderMatchPipelineService
{
    private const MAX_AI_SCORES_PER_RUN = 30;

    public function __construct(
        private readonly OrderMatchingService $legacyMatching,
        private readonly OrderMatchAiMatchingService $aiMatching,
        private readonly OrderMatchDuplicateDetector $duplicateDetector,
        private readonly OrderMatchPoNormalizer $normalizer,
        private readonly CustomerPoMatchResolver $poResolver,
        private readonly OrderMatchNotificationService $notifications,
    ) {
    }

    public function runExtractionAndMatching(?int $userId = null): array
    {
        $extraction = $this->legacyMatching->runPoExtraction();
        $this->syncCanonicalFields();
        $matchRun = $this->legacyMatching->runOrderMatching($userId);

        $batch = Email::with('mailboxFolder.customer')
            ->where('po_extraction_attempted', true)
            ->whereNull('reviewer_decision')
            ->where('received_at', '>=', now()->subDays(7))
            ->whereNotNull('canonical_po')
            ->where(function ($query) {
                $query->whereNull('matched_order_id')
                    ->orWhereNotIn('match_classification', ['matched', 'matched_discrepancies']);
            })
            ->orderByDesc('received_at')
            ->limit(500)
            ->get();

        $scored = 0;
        $skippedAi = 0;
        foreach ($batch as $email) {
            $dup = $this->duplicateDetector->detect($email, $email->canonical_po, $batch);
            if ($dup['flag']) {
                $email->update([
                    'duplicate_flag'     => $dup['flag'],
                    'canonical_email_id' => $dup['canonical_email_id'],
                ]);
            }

            if ($dup['flag'] === 'PO_CUSTOMER_MISMATCH') {
                continue;
            }

            if ($scored >= self::MAX_AI_SCORES_PER_RUN) {
                $skippedAi++;
                continue;
            }

            try {
                $this->aiMatching->score($email);
                $scored++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('order_match_ai_score_failed', [
                    'email_id' => $email->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $notificationResults = [];
        try {
            $notificationResults = $this->notifications->evaluateAll();
        } catch (\Throwable $e) {
            $notificationResults = ['error' => $e->getMessage()];
        }

        return [
            'extraction'       => $extraction,
            'match_run'        => $matchRun,
            'ai_scored'        => $scored,
            'ai_skipped'       => $skippedAi,
            'ai_batch_limit'   => self::MAX_AI_SCORES_PER_RUN,
            'notifications'    => $notificationResults,
        ];
    }

    private function syncCanonicalFields(): void
    {
        Email::with('mailboxFolder.customer')
            ->where('po_extraction_attempted', true)
            ->whereNull('canonical_po')
            ->chunkById(200, function ($emails): void {
                foreach ($emails as $email) {
                    $po = $email->extracted_po_number
                        ? $this->poResolver->toCanonicalMatchKey(
                            $email->extracted_po_number,
                            $email->from_email,
                            $email->mailboxFolder?->customer?->name,
                            $email->subject,
                        )
                        : null;
                    $status = $po ? 'found' : ($email->match_reason_codes && in_array('multiple_po_candidates', $email->match_reason_codes ?? [], true) ? 'conflict' : 'not_found');

                    $email->update([
                        'canonical_po'      => $po,
                        'extraction_status' => $status,
                        'match_status'      => $email->match_status === 'pending' ? 'pending' : $email->match_status,
                    ]);
                }
            });
    }
}