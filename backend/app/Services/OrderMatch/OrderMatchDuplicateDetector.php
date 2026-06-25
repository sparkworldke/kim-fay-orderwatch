<?php

namespace App\Services\OrderMatch;

use App\Models\Email;
use App\Models\MatchLog;
use Illuminate\Support\Collection;

class OrderMatchDuplicateDetector
{
    public function __construct(private readonly OrderMatchPoNormalizer $normalizer)
    {
    }

    /** @return array{flag: ?string, canonical_email_id: ?int} */
    public function detect(Email $email, ?string $canonicalPo, Collection $batchEmails): array
    {
        $po = $this->normalizer->normalise($canonicalPo ?? $email->canonical_po ?? $email->extracted_po_number);
        if (! $po) {
            return ['flag' => null, 'canonical_email_id' => null];
        }

        $customerIds = $this->resolveCustomerIds($email, $batchEmails, $po);
        if (count($customerIds) > 1) {
            return ['flag' => 'PO_CUSTOMER_MISMATCH', 'canonical_email_id' => null];
        }

        $priorAccepted = MatchLog::where('canonical_po', $po)
            ->where('status', 'accepted')
            ->where('email_id', '!=', $email->id)
            ->exists();

        if ($priorAccepted) {
            return ['flag' => 'previously_matched', 'canonical_email_id' => null];
        }

        $duplicates = $batchEmails->filter(function (Email $other) use ($email, $po) {
            if ($other->id === $email->id) {
                return false;
            }
            $otherPo = $this->normalizer->normalise($other->canonical_po ?? $other->extracted_po_number);

            return $otherPo === $po;
        });

        if ($duplicates->isNotEmpty()) {
            $canonicalId = $duplicates->sortBy('received_at')->first()?->id;

            return ['flag' => 'duplicate', 'canonical_email_id' => $canonicalId];
        }

        return ['flag' => null, 'canonical_email_id' => null];
    }

    /** @return list<string> */
    private function resolveCustomerIds(Email $email, Collection $batchEmails, string $po): array
    {
        $ids = [];

        if ($email->mailboxFolder?->customer?->acumatica_id) {
            $ids[] = $email->mailboxFolder->customer->acumatica_id;
        }

        foreach ($batchEmails as $other) {
            $otherPo = $this->normalizer->normalise($other->canonical_po ?? $other->extracted_po_number);
            if ($otherPo !== $po || $other->id === $email->id) {
                continue;
            }
            $custId = $other->mailboxFolder?->customer?->acumatica_id;
            if ($custId) {
                $ids[] = $custId;
            }
        }

        return array_values(array_unique($ids));
    }
}