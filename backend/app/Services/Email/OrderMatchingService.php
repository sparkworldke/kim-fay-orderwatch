<?php

namespace App\Services\Email;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\EmailMatchAttempt;
use App\Models\OrderMatchRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderMatchingService
{
    public const RULE_VERSION = 'po-email-v2';
    private ?int $activeCronRunLogId = null;

    public function __construct(
        private readonly PoNumberExtractorService $extractor,
        private readonly SupportingFieldComparator $comparator,
    ) {
    }

    /** @return array{processed:int,extracted:int} */
    public function runPoExtraction(): array
    {
        $processed = 0;
        $extracted = 0;
        $configs = EmailImportConfig::where('is_active', true)->get();

        if ($configs->isEmpty()) {
            return compact('processed', 'extracted');
        }

        Email::with('attachments')->where('po_extraction_attempted', false)
            ->orderByDesc('received_at')
            ->chunkById(200, function ($emails) use ($configs, &$processed, &$extracted): void {
                foreach ($emails as $email) {
                    $email->po_extraction_attempted = true;
                    $config = $configs->first(fn ($item) => $item->matchesSender($email->from_email ?? ''));

                    if (! $config) {
                        $email->save();
                        $processed++;
                        continue;
                    }

                    $sources = [
                        'subject' => $email->subject,
                        'body' => $email->body_content ?: $email->body_preview,
                    ];
                    foreach ($email->attachments as $attachment) {
                        if ($attachment->name) {
                            $sources['attachment_filename:'.$attachment->id] = $attachment->name;
                        }
                        if ($attachment->extracted_text) {
                            $sources['attachment_content:'.$attachment->id] = $attachment->extracted_text;
                        }
                    }

                    $candidates = $this->extractor->extractAll($email->from_email ?? '', $sources);
                    foreach ($candidates as &$candidate) {
                        if (str_starts_with($candidate['source'], 'attachment_content:')) {
                            $attachmentId = (int) str($candidate['source'])->after(':')->value();
                            $attachment = $email->attachments->firstWhere('id', $attachmentId);
                            if ($attachment && ($attachment->extraction_confidence ?? 100) < 100) {
                                $candidate['confidence'] = min($candidate['confidence'], (int) $attachment->extraction_confidence);
                            }
                        }
                    }
                    unset($candidate);

                    $unique = collect($candidates)->pluck('po_number')->unique()->values();
                    $chosen = $unique->count() === 1 ? $unique->first() : null;
                    $best = collect($candidates)->sortByDesc('confidence')->first();

                    $email->fill([
                        'extracted_po_number' => $chosen,
                        'po_extraction_method' => $best['method'] ?? null,
                        'po_extraction_confidence' => $best['confidence'] ?? null,
                        'match_sources' => collect($candidates)->pluck('source')->unique()->values()->all(),
                        'match_evidence' => $candidates,
                        'match_rule_version' => self::RULE_VERSION,
                    ]);

                    if ($unique->count() > 1) {
                        $email->match_classification = 'needs_review';
                        $email->match_reason_codes = ['multiple_po_candidates'];
                    }

                    $email->save();
                    $processed++;
                    $extracted += $chosen !== null ? 1 : 0;
                }
            });

        return compact('processed', 'extracted');
    }

    public function runOrderMatching(?int $userId = null, ?int $cronRunLogId = null): OrderMatchRun
    {
        $this->activeCronRunLogId = $cronRunLogId;
        $run = OrderMatchRun::create([
            'triggered_by_user_id' => $userId,
            'cron_run_log_id' => $cronRunLogId,
            'started_at' => now(),
            'status' => 'running',
        ]);
        $counts = ['matched' => 0, 'unmatched' => 0, 'duplicate' => 0, 'missing_in_acumatica' => 0];

        try {
            $emails = Email::with(['attachments', 'matchedOrder'])
                ->where('po_extraction_attempted', true)
                ->where(function ($query) {
                    $query->whereNull('ingestion_classification')
                        ->orWhereIn('ingestion_classification', ['po_processing', 'needs_review']);
                })
                ->whereNull('reviewer_decision')
                ->get();

            foreach ($emails as $email) {
                DB::transaction(function () use ($email, $userId, &$counts): void {
                    $classification = $this->classify($email, $userId);
                    match ($classification) {
                        'matched', 'matched_discrepancies' => $counts['matched']++,
                        'needs_review' => $counts['duplicate']++,
                        default => $counts['unmatched']++,
                    };
                });
            }

            AcumaticaSalesOrder::whereNull('email_received_at')
                ->whereNotIn('match_status', ['matched', 'matched_discrepancies', 'needs_review'])
                ->whereDate('order_date', '>=', now()->subDays(90))
                ->update(['match_status' => 'missing', 'flag_source' => 'email']);

            $counts['missing_in_acumatica'] = Email::where('match_classification', 'not_matched')
                ->whereNotNull('extracted_po_number')->count();

            $run->update([
                'ended_at' => now(), 'status' => 'completed', 'emails_processed' => $emails->count(),
                ...$counts, 'summary' => [...$counts, 'rule_version' => self::RULE_VERSION],
            ]);
        } catch (\Throwable $exception) {
            $run->update(['ended_at' => now(), 'status' => 'failed', 'error_message' => $exception->getMessage()]);
            Log::error('Order matching run failed', ['run_id' => $run->id, 'error' => $exception->getMessage()]);
        }

        $result = $run->fresh();
        $this->activeCronRunLogId = null;
        return $result;
    }

    private function classify(Email $email, ?int $userId): string
    {
        $evidence = collect($email->match_evidence ?? []);
        $reasonCodes = [];

        if ($evidence->isEmpty() && $email->extracted_po_number) {
            $evidence = collect([[
                'po_number' => $this->normalise($email->extracted_po_number), 'source' => 'legacy_extraction',
                'method' => $email->po_extraction_method ?? 'legacy', 'confidence' => $email->po_extraction_confidence ?? 100,
                'raw_match' => $email->extracted_po_number, 'deterministic' => ! str_contains((string) $email->po_extraction_method, 'ai'),
            ]]);
        }

        $unique = $evidence->pluck('po_number')->map(fn ($value) => $this->normalise((string) $value))->unique()->values();
        if ($unique->count() > 1) {
            return $this->record($email, null, $userId, $evidence, [], ['multiple_po_candidates'], 'needs_review');
        }

        if ($unique->isEmpty()) {
            $thread = $this->threadEvidence($email);
            if ($thread->isNotEmpty()) {
                $evidence = $thread;
                $unique = $thread->pluck('po_number')->unique()->values();
                $reasonCodes[] = 'thread_only_evidence';
            }
        }

        if ($unique->count() !== 1) {
            return $this->record($email, null, $userId, $evidence, [], ['po_not_found'], 'not_matched');
        }

        $po = $unique->first();
        $isAiOnly = $evidence->every(fn ($item) => ! ($item['deterministic'] ?? false));
        $lowConfidence = $evidence->max('confidence') < 100;
        $threadOnly = $evidence->every(fn ($item) => ($item['source'] ?? '') === 'thread_history');
        $trustedThread = $threadOnly && $evidence->contains(fn ($item) => ($item['trusted_thread'] ?? false) === true);

        $orders = $this->ordersForPo($po);
        if ($orders->count() > 1) {
            foreach ($orders as $order) {
                $order->update(['match_status' => 'needs_review', 'flag_source' => 'email']);
            }
            return $this->record($email, null, $userId, $evidence, [], ['non_unique_acumatica_customer_order'], 'needs_review');
        }

        if ($orders->isEmpty()) {
            return $this->record($email, null, $userId, $evidence, [], ['po_not_found_in_acumatica'], 'not_matched');
        }

        $order = $orders->first();
        if ($isAiOnly || $lowConfidence || ($threadOnly && ! $trustedThread)) {
            if ($isAiOnly) $reasonCodes[] = 'ai_context_only';
            if ($lowConfidence) $reasonCodes[] = 'uncertain_extraction';
            $order->update(['match_status' => 'needs_review', 'flag_source' => 'email']);
            return $this->record($email, $order, $userId, $evidence, [], array_values(array_unique($reasonCodes)), 'needs_review', false);
        }

        $text = collect([$email->body_content, $email->body_preview])
            ->merge($email->attachments->pluck('extracted_text'))->filter()->implode("\n");
        $conflicts = $this->comparator->compare($order, $text);
        $classification = $conflicts === [] ? 'matched' : 'matched_discrepancies';
        if ($conflicts !== []) $reasonCodes[] = 'material_supporting_field_conflict';

        return $this->record($email, $order, $userId, $evidence, $conflicts, $reasonCodes, $classification, true);
    }

    private function record(Email $email, ?AcumaticaSalesOrder $order, ?int $userId, Collection $evidence, array $conflicts, array $reasons, string $classification, bool $link = false, array $auditContext = []): string
    {
        $po = $evidence->pluck('po_number')->unique()->count() === 1 ? $evidence->first()['po_number'] : null;
        $sources = $evidence->pluck('source')->unique()->values()->all();

        $email->fill([
            'matched_order_id' => $link ? $order?->id : null,
            'match_classification' => $classification,
            'match_sources' => $sources,
            'match_evidence' => $evidence->values()->all(),
            'match_conflicts' => $conflicts,
            'match_reason_codes' => $reasons,
            'match_rule_version' => self::RULE_VERSION,
        ])->save();

        if ($order) {
            $order->update([
                'match_status' => $classification,
                'flag_source' => $classification === 'matched' ? null : 'email',
                'email_subject' => $email->subject,
                'email_received_at' => $email->received_at,
            ]);
        }

        EmailMatchAttempt::create([
            'email_id' => $email->id, 'order_id' => $order?->id, 'actor_user_id' => $userId,
            'cron_run_log_id' => $this->activeCronRunLogId,
            'rule_version' => self::RULE_VERSION, 'searched_po' => $po,
            'candidates' => $evidence->pluck('po_number')->unique()->values()->all(), 'sources' => $sources,
            'normalization' => array_merge(['uppercase' => true, 'trim_outer_whitespace' => true, 'punctuation_preserved' => true, 'leading_zeroes_preserved' => true], $auditContext),
            'conflicts' => $conflicts, 'reason_codes' => $reasons, 'classification' => $classification,
            'confidence' => $evidence->max('confidence'),
        ]);

        return $classification;
    }

    private function threadEvidence(Email $email): Collection
    {
        if (! $email->conversation_id) return collect();

        return Email::where('mailbox_account_id', $email->mailbox_account_id)
            ->where('conversation_id', $email->conversation_id)->whereKeyNot($email->id)
            ->where('received_at', '<=', $email->received_at)->whereNotNull('extracted_po_number')
            ->get()->map(fn ($prior) => [
                'po_number' => $this->normalise($prior->extracted_po_number), 'source' => 'thread_history',
                'method' => 'verified_conversation_id', 'confidence' => 100, 'raw_match' => $prior->extracted_po_number,
                'deterministic' => true, 'source_email_id' => $prior->id,
                'trusted_thread' => $prior->matched_order_id !== null,
            ]);
    }

    private function ordersForPo(string $po): Collection
    {
        return AcumaticaSalesOrder::with('lines')
            ->whereRaw('UPPER(TRIM(customer_order)) = ?', [$this->normalise($po)])->get();
    }

    public function manualPoOverride(Email $email, string $poNumber, int $userId, string $reason): ?AcumaticaSalesOrder
    {
        $po = $this->normalise($poNumber);
        $previous = $email->extracted_po_number;
        $orders = $this->ordersForPo($po);
        $order = $orders->count() === 1 ? $orders->first() : null;
        $classification = $orders->count() > 1 ? 'needs_review' : ($order ? 'matched' : 'not_matched');

        $email->fill([
            'extracted_po_number' => $po, 'po_extraction_method' => 'manual', 'po_extraction_confidence' => 100,
            'po_extraction_attempted' => true, 'reviewer_decision' => 'manual_override', 'reviewer_reason' => $reason,
            'reviewed_by' => $userId, 'reviewed_at' => now(),
        ])->save();

        $evidence = collect([[
            'po_number' => $po, 'source' => 'manual', 'method' => 'manual', 'confidence' => 100,
            'raw_match' => $poNumber, 'deterministic' => true, 'previous_value' => $previous, 'reason' => $reason,
        ]]);
        $this->record(
            $email, $order, $userId, $evidence, [],
            [$classification === 'needs_review' ? 'non_unique_acumatica_customer_order' : 'manual_override'],
            $classification, $order !== null,
            ['manual_previous_value' => $previous, 'manual_reason' => $reason],
        );

        return $order;
    }

    public function review(Email $email, string $decision, string $reason, int $userId): Email
    {
        $attempt = $email->matchAttempts()->latest('created_at')->first();
        $order = $attempt?->order_id ? AcumaticaSalesOrder::find($attempt->order_id) : $email->matchedOrder;
        $updates = ['reviewer_decision' => $decision, 'reviewer_reason' => $reason, 'reviewed_by' => $userId, 'reviewed_at' => now()];
        if ($decision === 'approved' && $order) {
            $updates += ['matched_order_id' => $order->id, 'match_classification' => 'matched'];
            $order->update(['match_status' => 'matched', 'flag_source' => null]);
        } elseif ($decision === 'rejected') {
            $updates += ['matched_order_id' => null, 'match_classification' => 'not_matched'];
            if ($order && $order->match_status !== 'matched') $order->update(['match_status' => 'missing', 'flag_source' => 'email']);
        }
        $email->update($updates);
        EmailMatchAttempt::create([
            'email_id' => $email->id, 'order_id' => $email->matched_order_id, 'actor_user_id' => $userId,
            'cron_run_log_id' => null,
            'rule_version' => self::RULE_VERSION, 'searched_po' => $email->extracted_po_number,
            'candidates' => collect($email->match_evidence ?? [])->pluck('po_number')->unique()->values()->all(),
            'sources' => $email->match_sources ?? [],
            'normalization' => ['manual_review' => true, 'reviewer_decision' => $decision, 'reviewer_reason' => $reason],
            'conflicts' => $email->match_conflicts ?? [], 'reason_codes' => ['reviewer_decision'],
            'classification' => $email->fresh()->match_classification ?? $decision, 'confidence' => $email->po_extraction_confidence,
        ]);
        return $email->fresh(['attachments', 'matchedOrder', 'matchAttempts']);
    }

    private function normalise(string $po): string
    {
        return strtoupper(trim($po));
    }
}
