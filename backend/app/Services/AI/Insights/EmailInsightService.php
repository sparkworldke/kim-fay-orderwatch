<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

class EmailInsightService
{
    public function getSnapshot(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $from = $dateFrom ?? now()->startOfDay()->toDateTimeString();
        $to   = $dateTo   ?? now()->endOfDay()->toDateTimeString();

        // Core counts for the period
        $counts = DB::table('emails')
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw('
                COUNT(*) as total_received,
                SUM(CASE WHEN ingestion_classification = "processed" THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN ingestion_classification = "skipped" THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN match_classification IS NULL OR match_classification = "unmatched" THEN 1 ELSE 0 END) as unmatched,
                SUM(CASE WHEN ingestion_review_status = "pending" THEN 1 ELSE 0 END) as awaiting_review,
                SUM(CASE WHEN extracted_po_number IS NOT NULL THEN 1 ELSE 0 END) as with_po_detected,
                SUM(CASE WHEN po_extraction_attempted = 1 THEN 1 ELSE 0 END) as po_extraction_attempted,
                SUM(CASE WHEN has_attachments = 1 THEN 1 ELSE 0 END) as with_attachments
            ')
            ->first();

        // Skipped by reason
        $skippedByReason = DB::table('emails')
            ->whereBetween('received_at', [$from, $to])
            ->where('ingestion_classification', 'skipped')
            ->whereNotNull('ingestion_reason_codes')
            ->selectRaw('ingestion_reason_codes, COUNT(*) as count')
            ->groupBy('ingestion_reason_codes')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->toArray();

        // By folder
        $byFolder = DB::table('emails')
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw('folder, COUNT(*) as count')
            ->groupBy('folder')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'folder')
            ->toArray();

        // Top senders (by volume)
        $topSenders = DB::table('emails')
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw('from_email, COUNT(*) as count')
            ->groupBy('from_email')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'from_email')
            ->toArray();

        // All-time unmatched awaiting review (not just today — operational concern)
        $allTimeAwaitingReview = DB::table('emails')
            ->where('ingestion_review_status', 'pending')
            ->count();

        $allTimeUnmatched = DB::table('emails')
            ->where(function ($q) {
                $q->whereNull('match_classification')
                  ->orWhere('match_classification', 'unmatched');
            })
            ->count();

        return [
            'period'                => ['from' => $from, 'to' => $to],
            'total_received'        => (int) ($counts->total_received ?? 0),
            'processed'             => (int) ($counts->processed ?? 0),
            'skipped'               => (int) ($counts->skipped ?? 0),
            'unmatched'             => (int) ($counts->unmatched ?? 0),
            'awaiting_review'       => (int) ($counts->awaiting_review ?? 0),
            'with_po_detected'      => (int) ($counts->with_po_detected ?? 0),
            'po_extraction_attempted'=> (int) ($counts->po_extraction_attempted ?? 0),
            'with_attachments'      => (int) ($counts->with_attachments ?? 0),
            'skipped_by_reason'     => array_map(fn($r) => (array) $r, $skippedByReason),
            'by_folder'             => $byFolder,
            'top_senders'           => $topSenders,
            'all_time_awaiting_review' => $allTimeAwaitingReview,
            'all_time_unmatched'    => $allTimeUnmatched,
            'formulas' => [
                'unmatched' => 'emails WHERE match_classification IS NULL OR = "unmatched"',
                'awaiting_review' => 'emails WHERE ingestion_review_status = "pending"',
            ],
        ];
    }
}
