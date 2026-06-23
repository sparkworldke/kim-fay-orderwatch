<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\EmailImportConfig;

class EmailIngestionDecisionService
{
    public function __construct(private readonly PoNumberExtractorService $extractor) {}

    /** @return array{classification:string,reasons:array,sources:array,po_detected:bool,po_source:?string} */
    public function evaluate(Email $email): array
    {
        $email->loadMissing(['attachments', 'mailboxFolder.rules']);
        $folder = $email->mailboxFolder;
        $sources = ['subject' => $email->subject, 'body' => $email->body_content ?: $email->body_preview];
        foreach ($email->attachments as $attachment) {
            if ($attachment->name) $sources['attachment_filename:'.$attachment->id] = $attachment->name;
            if ($attachment->extracted_text) $sources['attachment_content:'.$attachment->id] = $attachment->extracted_text;
        }

        $evidence = $this->extractor->extractDeterministicAll($email->from_email ?? '', $sources);
        $unverifiedThread = false;
        if ($evidence === [] && $email->conversation_id) {
            $prior = Email::where('mailbox_account_id', $email->mailbox_account_id)
                ->where('conversation_id', $email->conversation_id)->whereKeyNot($email->id)
                ->whereNotNull('extracted_po_number')->orderByDesc('received_at')->first();
            if ($prior?->matched_order_id) {
                $evidence[] = [
                    'po_number' => strtoupper(trim($prior->extracted_po_number)), 'source' => 'thread_history',
                    'method' => 'verified_conversation_id', 'confidence' => 100,
                    'raw_match' => $prior->extracted_po_number, 'deterministic' => true,
                    'source_email_id' => $prior->id, 'trusted_thread' => true,
                ];
            } elseif ($prior) {
                $unverifiedThread = true;
            }
        }
        $unique = collect($evidence)->pluck('po_number')->unique()->values();
        $senderAllowed = EmailImportConfig::where('is_active', true)->get()
            ->contains(fn ($config) => $config->matchesSender($email->from_email ?? ''));
        $folderTrusted = $folder && $folder->is_order_folder && $folder->trust_level === 'trusted_order';
        $customerMapped = $folder?->customer_id !== null;
        $ruleTrusted = $folder?->rules->contains(fn ($rule) => $rule->is_enabled && $rule->is_trusted) ?? false;

        $decisionSources = array_values(array_filter([
            $senderAllowed ? 'sender_allowed' : null,
            $folderTrusted ? 'folder_trusted' : null,
            $customerMapped ? 'folder_customer_mapped' : null,
            $ruleTrusted ? 'existing_rule_trusted' : null,
            $evidence !== [] ? 'po_number_detected' : null,
            $unverifiedThread ? 'unverified_thread_context' : null,
        ]));

        if ($evidence !== []) {
            $classification = 'po_processing';
            $reasons = $unique->count() > 1 ? ['po_number_detected', 'multiple_po_candidates'] : ['po_number_detected'];
        } elseif ($senderAllowed || $folderTrusted || $customerMapped || $ruleTrusted || $unverifiedThread) {
            $classification = 'needs_review';
            $reasons = [$unverifiedThread ? 'unverified_thread_context' : ($folderTrusted || $customerMapped || $ruleTrusted ? 'folder_trusted_but_po_missing' : 'allowed_sender_po_missing')];
        } else {
            $classification = 'stored_non_order';
            $reasons = ['stored_non_order'];
        }

        $best = collect($evidence)->sortByDesc('confidence')->first();
        $email->fill([
            'ingestion_classification' => $classification,
            'ingestion_reason_codes' => $reasons,
            'ingestion_decision_sources' => $decisionSources,
            'po_extraction_attempted' => true,
            'extracted_po_number' => $unique->count() === 1 ? $unique->first() : null,
            'po_extraction_method' => $best['method'] ?? null,
            'po_extraction_confidence' => $best['confidence'] ?? null,
            'match_sources' => collect($evidence)->pluck('source')->unique()->values()->all(),
            'match_evidence' => $evidence,
            'match_rule_version' => OrderMatchingService::RULE_VERSION,
        ]);
        if ($unique->count() > 1) {
            $email->match_classification = 'needs_review';
            $email->match_reason_codes = ['multiple_po_candidates'];
        }
        $email->save();

        return [
            'classification' => $classification,
            'reasons' => $reasons,
            'sources' => $decisionSources,
            'po_detected' => $evidence !== [],
            'po_source' => $best['source'] ?? null,
        ];
    }
}
