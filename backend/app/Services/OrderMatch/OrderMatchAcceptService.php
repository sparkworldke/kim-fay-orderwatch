<?php

namespace App\Services\OrderMatch;

use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Models\MatchLog;
use App\Models\MatchPrediction;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderMatchAcceptService
{
    public function __construct(private readonly OrderMatchPoNormalizer $normalizer)
    {
    }

    public function accept(Email $email, int $userId): array
    {
        if (in_array($email->duplicate_flag, ['duplicate', 'PO_CUSTOMER_MISMATCH'], true) && ! $email->canonical_email_id) {
            throw new HttpException(409, 'Nominate a canonical email before accepting a duplicate-flagged match.');
        }

        $prediction = MatchPrediction::where('email_id', $email->id)->where('is_top_prediction', true)->first();
        if (! $prediction?->order_id) {
            throw new HttpException(422, 'No prediction with order to accept.');
        }

        $order = AcumaticaSalesOrder::findOrFail($prediction->order_id);
        $po = $this->normalizer->normalise($email->canonical_po ?? $email->extracted_po_number);

        return DB::transaction(function () use ($email, $userId, $prediction, $order, $po) {
            $email->update([
                'matched_order_id'     => $order->id,
                'match_status'         => 'accepted',
                'match_classification' => 'matched',
                'reviewer_decision'    => 'approved',
                'reviewed_by'          => $userId,
                'reviewed_at'          => now(),
            ]);

            $order->update([
                'match_status'      => 'matched',
                'flag_source'       => null,
                'email_subject'     => $email->subject,
                'email_received_at' => $email->received_at,
            ]);

            $log = MatchLog::create([
                'email_id'       => $email->id,
                'prediction_id'  => $prediction->id,
                'order_nbr'      => $order->acumatica_order_nbr,
                'status'         => 'accepted',
                'canonical_po'   => $po,
                'accepted_by'    => $userId,
                'accepted_at'    => now(),
                'metadata'       => ['event' => 'match.accepted', 'confidence' => $prediction->confidence],
            ]);

            return [
                'match_log_id' => $log->id,
                'order_nbr'    => $order->acumatica_order_nbr,
                'status'       => 'accepted',
                'accepted_by'  => $userId,
                'accepted_at'  => $log->accepted_at,
            ];
        });
    }

    public function reject(Email $email, string $reason, int $userId): array
    {
        $prediction = MatchPrediction::where('email_id', $email->id)->where('is_top_prediction', true)->first();
        $po = $this->normalizer->normalise($email->canonical_po ?? $email->extracted_po_number);

        $email->update([
            'match_status'      => 'rejected',
            'reviewer_decision' => 'rejected',
            'reviewer_reason'   => $reason,
            'reviewed_by'       => $userId,
            'reviewed_at'       => now(),
        ]);

        $log = MatchLog::create([
            'email_id'         => $email->id,
            'prediction_id'    => $prediction?->id,
            'order_nbr'        => $prediction?->order_nbr,
            'status'           => 'rejected',
            'canonical_po'     => $po,
            'accepted_by'      => $userId,
            'accepted_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        return [
            'match_log_id' => $log->id,
            'status'       => 'rejected',
            'accepted_by'  => $userId,
            'accepted_at'  => $log->accepted_at,
        ];
    }

    public function markDuplicate(Email $email, int $canonicalEmailId, int $userId): array
    {
        $canonical = Email::findOrFail($canonicalEmailId);
        $po = $this->normalizer->normalise($email->canonical_po ?? $email->extracted_po_number);

        $email->update([
            'duplicate_flag'     => 'duplicate',
            'canonical_email_id' => $canonical->id,
            'match_status'       => 'duplicate_acknowledged',
        ]);

        $log = MatchLog::create([
            'email_id'           => $email->id,
            'status'             => 'duplicate_acknowledged',
            'canonical_po'       => $po,
            'accepted_by'        => $userId,
            'accepted_at'        => now(),
            'canonical_email_id' => $canonical->id,
            'metadata'           => ['canonical_email_id' => $canonical->id],
        ]);

        return ['match_log_id' => $log->id, 'status' => 'duplicate_acknowledged'];
    }
}