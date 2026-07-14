<?php

namespace App\Services\Operations;

use App\Models\AcumaticaBackorderLine;

/**
 * Fill-rate / backorder reason validation — delegates to SalesOrderReasonCatalog.
 */
class FillRateReasonCatalog
{
    public const ISSUE_MISSING = SalesOrderReasonCatalog::ISSUE_MISSING;

    public const ISSUE_UNCLASSIFIED = SalesOrderReasonCatalog::ISSUE_UNCLASSIFIED;

    public function __construct(
        private readonly SalesOrderReasonCatalog $catalog,
    ) {
    }

    /** @return list<string> */
    public function approvedCodes(): array
    {
        return AcumaticaBackorderLine::REASON_CODES;
    }

    public function isApproved(?string $reasonCode): bool
    {
        if ($reasonCode === null || trim($reasonCode) === '') {
            return false;
        }

        return $this->catalog->resolveSubReason($reasonCode) !== null;
    }

    /**
     * @return array{issue: string, parent_reason_code: ?string, parent_reason_label: ?string, sub_reason: ?string, sub_reason_label: ?string}
     */
    public function classify(?string $reasonCode): array
    {
        $result = $this->catalog->classify(SalesOrderReasonCatalog::PARENT_FILL_RATE, $reasonCode);

        return [
            'issue' => $result['issue'],
            'parent_reason_code' => $result['parent_reason_code'],
            'parent_reason_label' => $result['parent_reason_label'],
            'sub_reason' => $result['sub_reason_code'],
            'sub_reason_label' => $result['sub_reason_label'],
        ];
    }

    public function formatLabel(string $reasonCode): string
    {
        $resolved = $this->catalog->resolveSubReason($reasonCode);

        return $resolved !== null
            ? $this->catalog->subReasonLabel($resolved)
            : $this->catalog->formatLabel($reasonCode);
    }
}