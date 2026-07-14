<?php

namespace App\Services\Operations;

/**
 * Canonical hierarchical reason taxonomy for SO workflow states and fill-rate/backorder capture.
 *
 * Display format: "{Parent Label} - {Sub-Reason Label}"
 * e.g. "Cancelled Order - Wrong code", "Backorder - Short Expiry"
 */
class SalesOrderReasonCatalog
{
    public const ISSUE_MISSING = 'missing';

    public const ISSUE_UNCLASSIFIED = 'unclassified';

    public const ISSUE_VALID = 'valid';

    /** Parent reason codes keyed by workflow context. */
    public const PARENT_CANCELLED_ORDER = 'cancelled_order';

    public const PARENT_REJECTED_ORDER = 'rejected_order';

    public const PARENT_ON_HOLD_ORDER = 'on_hold_order';

    public const PARENT_BACKORDER = 'backorder';

    public const PARENT_FILL_RATE = 'fill_rate_shortfall';

    /** @var array<string, string> */
    public const PARENT_LABELS = [
        self::PARENT_CANCELLED_ORDER => 'Cancelled Order',
        self::PARENT_REJECTED_ORDER => 'Rejected Order',
        self::PARENT_ON_HOLD_ORDER => 'On Hold Order',
        self::PARENT_BACKORDER => 'Backorder',
        self::PARENT_FILL_RATE => 'Fill Rate Shortfall',
    ];

    /**
     * Approved sub-reasons (slug => display label).
     *
     * @var array<string, string>
     */
    public const SUB_REASONS = [
        'out_of_stock_procurement' => 'Out of stock - Procurement',
        'out_of_stock_production' => 'Out of stock - Production',
        'delay_in_delivery' => 'Delay in delivery',
        'promo_product' => 'Promo product',
        'transfer_delays' => 'Transfer Delays',
        'short_expiry' => 'Short Expiry',
        'out_of_stock_msa' => 'Out of stock - MSA',
        'raw_material_stockout' => 'Raw material stockout',
        'discontinued' => 'Discontinued',
        'pb_discontinued' => 'PB Discontinued',
        'delayed_communication' => 'Delayed Communication',
        'truck_full' => 'Truck Full',
        'price_difference' => 'Price Difference',
        'invoicing_error' => 'Invoicing Error',
        'stock_variance' => 'Stock Variance',
        'isolation_error' => 'Isolation Error',
        'non_focus' => 'Non focus',
        'wrong_moq' => 'Wrong MOQ',
        'order_to_make' => 'Order To make',
        'kebs_stickers' => 'Kebs stickers',
        'wrong_product_description' => 'Wrong Product Description',
        'system_error' => 'System error',
        'conversion_delays' => 'Conversion delays',
        'wrong_code' => 'Wrong code',
        'price_variance' => 'Price Variance',
        'delayed_supplier_payment' => 'Delayed Supplier Payment',
        'lpo_error' => 'LPO Error',
        'batch_sequence' => 'Batch Sequence',
        'conversion_issues' => 'Conversion issues',
        'price_overcharge' => 'Price Overcharge',
        'npd' => 'NPD',
        'did_not_pick_on_shipment' => 'Did not pick on shipment',
        'production_stockout' => 'Production Stokout',
    ];

    /**
     * Sub-reason codes treated as out-of-stock for fill-rate exclusion / OOS reporting.
     *
     * @var list<string>
     */
    public const OUT_OF_STOCK_CODES = [
        'out_of_stock_procurement',
        'out_of_stock_production',
        'out_of_stock_msa',
        'raw_material_stockout',
        'production_stockout',
    ];

    public static function isOutOfStockReason(?string $code): bool
    {
        $normalized = strtolower(trim((string) $code));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, self::OUT_OF_STOCK_CODES, true)) {
            return true;
        }

        // Aliases / free-text fragments sometimes stored on lines.
        return str_contains($normalized, 'out_of_stock')
            || str_contains($normalized, 'out of stock')
            || str_contains($normalized, 'stockout')
            || str_contains($normalized, 'stock_out');
    }

    /**
     * Maps normalized Acumatica / legacy reason tokens to canonical sub-reason slugs.
     *
     * @var array<string, string>
     */
    public const ACUMATICA_ALIASES = [
        'short_expiry' => 'short_expiry',
        'short expiry' => 'short_expiry',
        'price_difference' => 'price_difference',
        'price difference' => 'price_difference',
        'price_overcharge' => 'price_overcharge',
        'price overcharge' => 'price_overcharge',
        'price_undercharge' => 'price_variance',
        'price undercharge' => 'price_variance',
        'price_variance' => 'price_variance',
        'price variance' => 'price_variance',
        'wrong_price' => 'wrong_code',
        'wrong price' => 'wrong_code',
        'wrong_account' => 'wrong_code',
        'wrong account' => 'wrong_code',
        'wrong_tax_code' => 'wrong_code',
        'wrong tax code' => 'wrong_code',
        'wrong_moq' => 'wrong_moq',
        'wrong moq' => 'wrong_moq',
        'wrong_code' => 'wrong_code',
        'wrong code' => 'wrong_code',
        'notavlb' => 'out_of_stock_procurement',
        'no_price' => 'price_difference',
        'no price' => 'price_difference',
        'slow_moving' => 'non_focus',
        'slow moving' => 'non_focus',
        'vatcn' => 'invoicing_error',
        'lost_by_driver' => 'did_not_pick_on_shipment',
        'lost by driver' => 'did_not_pick_on_shipment',
        'inventory_shortage' => 'out_of_stock_procurement',
        'supplier_delay' => 'delay_in_delivery',
        'production_issue' => 'out_of_stock_production',
        'logistics_disruption' => 'delay_in_delivery',
        'quality_hold' => 'isolation_error',
        'forecast_gap' => 'out_of_stock_procurement',
        'customer_change' => 'delayed_communication',
        'system_allocation' => 'system_error',
        'out_of_stock' => 'out_of_stock_procurement',
    ];

    /** @return list<string> */
    public function approvedSubReasonCodes(): array
    {
        return array_keys(self::SUB_REASONS);
    }

    public function parentLabel(string $parentCode): string
    {
        return self::PARENT_LABELS[$parentCode] ?? ucwords(str_replace('_', ' ', $parentCode));
    }

    public function subReasonLabel(string $subReasonCode): string
    {
        return self::SUB_REASONS[$subReasonCode] ?? $this->formatLabel($subReasonCode);
    }

    public function formatHierarchical(string $parentCode, string $subReasonCode): string
    {
        return $this->parentLabel($parentCode).' - '.$this->subReasonLabel($subReasonCode);
    }

    /**
     * Resolve workflow parent from SO status.
     */
    public function parentForStatus(?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            'canceled', 'cancelled' => self::PARENT_CANCELLED_ORDER,
            'rejected' => self::PARENT_REJECTED_ORDER,
            'on hold', 'credit hold', 'hold' => self::PARENT_ON_HOLD_ORDER,
            default => null,
        };
    }

    public function normalizeRaw(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return strtolower(str_replace([' ', '-', '/'], '_', trim($raw)));
    }

    public function resolveSubReason(?string $rawCode): ?string
    {
        $normalized = $this->normalizeRaw($rawCode);
        if ($normalized === null) {
            return null;
        }

        if (isset(self::SUB_REASONS[$normalized])) {
            return $normalized;
        }

        if (isset(self::ACUMATICA_ALIASES[$normalized])) {
            return self::ACUMATICA_ALIASES[$normalized];
        }

        // Try with spaces instead of underscores (Acumatica often uses "PRICE DIFFERENCE").
        $spaced = strtolower(str_replace('_', ' ', $normalized));
        if (isset(self::ACUMATICA_ALIASES[$spaced])) {
            return self::ACUMATICA_ALIASES[$spaced];
        }

        return null;
    }

    public function isApprovedSubReason(?string $subReasonCode): bool
    {
        return $subReasonCode !== null && isset(self::SUB_REASONS[$subReasonCode]);
    }

    /**
     * @return array{
     *   issue: string,
     *   parent_reason_code: ?string,
     *   parent_reason_label: ?string,
     *   sub_reason_code: ?string,
     *   sub_reason_label: ?string,
     *   hierarchical_label: ?string,
     *   raw_code: ?string
     * }
     */
    public function classify(?string $parentCode, ?string $rawCode): array
    {
        $raw = $rawCode !== null && trim($rawCode) !== '' ? trim($rawCode) : null;

        if ($raw === null) {
            return [
                'issue' => self::ISSUE_MISSING,
                'parent_reason_code' => $parentCode,
                'parent_reason_label' => $parentCode ? $this->parentLabel($parentCode) : null,
                'sub_reason_code' => null,
                'sub_reason_label' => null,
                'hierarchical_label' => null,
                'raw_code' => null,
            ];
        }

        $subSlug = $this->resolveSubReason($raw);

        if ($subSlug === null) {
            $normalized = $this->normalizeRaw($raw);

            return [
                'issue' => self::ISSUE_UNCLASSIFIED,
                'parent_reason_code' => $parentCode,
                'parent_reason_label' => $parentCode ? $this->parentLabel($parentCode) : null,
                'sub_reason_code' => $normalized,
                'sub_reason_label' => $this->formatLabel((string) $normalized),
                'hierarchical_label' => $parentCode
                    ? $this->parentLabel($parentCode).' - '.$this->formatLabel((string) $normalized)
                    : $this->formatLabel((string) $normalized),
                'raw_code' => $raw,
            ];
        }

        return [
            'issue' => self::ISSUE_VALID,
            'parent_reason_code' => $parentCode,
            'parent_reason_label' => $parentCode ? $this->parentLabel($parentCode) : null,
            'sub_reason_code' => $subSlug,
            'sub_reason_label' => $this->subReasonLabel($subSlug),
            'hierarchical_label' => $parentCode
                ? $this->formatHierarchical($parentCode, $subSlug)
                : $this->subReasonLabel($subSlug),
            'raw_code' => $raw,
        ];
    }

    public function formatLabel(string $code): string
    {
        return ucwords(str_replace('_', ' ', strtolower(trim($code))));
    }

    /**
     * Audit which of the 33 required sub-reasons appear in observed data.
     *
     * @param  iterable<string|null>  $observedRawCodes
     * @return array{
     *   required_count: int,
     *   observed_approved: list<string>,
     *   missing_required: list<string>,
     *   unclassified_observed: list<string>
     * }
     */
    public function auditRequiredCoverage(iterable $observedRawCodes): array
    {
        $observedApproved = [];
        $unclassified = [];

        foreach ($observedRawCodes as $raw) {
            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }
            $resolved = $this->resolveSubReason((string) $raw);
            if ($resolved !== null) {
                $observedApproved[$resolved] = true;
            } else {
                $unclassified[$this->normalizeRaw((string) $raw) ?? (string) $raw] = (string) $raw;
            }
        }

        $missing = [];
        foreach (array_keys(self::SUB_REASONS) as $required) {
            if (! isset($observedApproved[$required])) {
                $missing[] = $required;
            }
        }

        return [
            'required_count' => count(self::SUB_REASONS),
            'observed_approved' => array_keys($observedApproved),
            'missing_required' => $missing,
            'unclassified_observed' => array_values($unclassified),
        ];
    }
}