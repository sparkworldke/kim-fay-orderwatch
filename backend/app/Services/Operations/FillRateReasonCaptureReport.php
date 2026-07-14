<?php

namespace App\Services\Operations;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrderLine;
use Illuminate\Support\Collection;

/**
 * Validates root-cause capture on fill-rate shortfall lines and produces a
 * consolidated report segmented by Manufactured vs Trading (Partners) goods.
 */
class FillRateReasonCaptureReport
{
    public function __construct(
        private readonly FillRateReasonCatalog $reasonCatalog,
        private readonly FillRateBusinessCategory $businessCategory,
    ) {
    }

    /**
     * @param  iterable<int, object>  $lines  Rows with: order_nbr, inventory_id, unfilled_reason_code,
     *                                         qty_at_approval, order_qty, qty_on_shipments, unit_price, sales_order_id
     * @param  array<string, ?string>  $productTypes  inventory_id => product_type
     * @return array{
     *   summary: array{
     *     total_shortfall_lines: int,
     *     total_shortfall_orders: int,
     *     valid_reason_lines: int,
     *     missing_reason_lines: int,
     *     unclassified_reason_lines: int,
     *     capture_rate_pct: ?float
     *   },
     *   by_business_category: array<string, array{
     *     business_category: string,
     *     label: string,
     *     line_count: int,
     *     order_count: int,
     *     undershipped_value: float,
     *     valid_reason_lines: int,
     *     missing_reason_lines: int,
     *     unclassified_reason_lines: int
     *   }>,
     *   breakdown: list<array{
     *     business_category: string,
     *     parent_reason: string,
     *     parent_reason_code: ?string,
     *     sub_reason: string,
     *     sub_reason_label: string,
     *     line_count: int,
     *     order_count: int,
     *     undershipped_value: float
     *   }>,
     *   flagged_records: list<array{
     *     order_nbr: string,
     *     customer_acumatica_id: ?string,
     *     inventory_id: string,
     *     reason_code: ?string,
     *     issue: string,
     *     business_category: string,
     *     undershipped_value: float
     *   }>
     * }
     */
    public function build(iterable $lines, array $productTypes = []): array
    {
        $shortfallLines = [];
        foreach ($lines as $line) {
            $demand = max((float) ($line->qty_at_approval ?? 0), (float) ($line->order_qty ?? 0));
            $onShipments = (float) ($line->qty_on_shipments ?? 0);
            if ($demand <= 0 || $onShipments >= $demand) {
                continue;
            }

            $shortfallLines[] = $line;
        }

        if ($shortfallLines === []) {
            return $this->emptyReport();
        }

        $missing = 0;
        $unclassified = 0;
        $valid = 0;
        $flagged = [];
        $orderIds = [];
        $categoryBuckets = $this->emptyCategoryBuckets();
        $breakdownAcc = [];

        foreach ($shortfallLines as $line) {
            $inventoryId = (string) ($line->inventory_id ?? '');
            $category = $this->businessCategory->classify(
                $inventoryId,
                $productTypes[$inventoryId] ?? null,
            );
            $categoryLabel = $this->businessCategory->label($category);
            $reasonCode = is_string($line->unfilled_reason_code ?? null)
                ? trim((string) $line->unfilled_reason_code)
                : null;
            $classification = $this->reasonCatalog->classify($reasonCode === '' ? null : $reasonCode);

            $demand = max((float) ($line->qty_at_approval ?? 0), (float) ($line->order_qty ?? 0));
            $value = max($demand - (float) ($line->qty_on_shipments ?? 0), 0) * (float) ($line->unit_price ?? 0);
            $orderNbr = (string) ($line->order_nbr ?? '');
            $orderId = $line->sales_order_id ?? null;

            if ($orderId !== null) {
                $orderIds[(string) $orderId] = true;
            }

            if ($classification['issue'] === FillRateReasonCatalog::ISSUE_MISSING) {
                $missing++;
                $categoryBuckets[$category]['missing_reason_lines']++;
            } elseif ($classification['issue'] === FillRateReasonCatalog::ISSUE_UNCLASSIFIED) {
                $unclassified++;
                $categoryBuckets[$category]['unclassified_reason_lines']++;
            } else {
                $valid++;
                $categoryBuckets[$category]['valid_reason_lines']++;
            }

            $categoryBuckets[$category]['line_count']++;
            $categoryBuckets[$category]['order_ids'][(string) $orderId] = true;
            $categoryBuckets[$category]['undershipped_value'] += $value;

            $parentLabel = $classification['parent_reason_label']
                ?? ($classification['issue'] === FillRateReasonCatalog::ISSUE_MISSING ? 'Missing Reason' : 'Unclassified Reason');
            $subReason = $classification['sub_reason']
                ?? ($classification['issue'] === FillRateReasonCatalog::ISSUE_MISSING ? 'missing' : 'unclassified');
            $subLabel = $classification['sub_reason_label']
                ?? ($classification['issue'] === FillRateReasonCatalog::ISSUE_MISSING ? 'Missing' : 'Unclassified');

            $breakdownKey = "{$category}|{$parentLabel}|{$subReason}";
            if (! isset($breakdownAcc[$breakdownKey])) {
                $breakdownAcc[$breakdownKey] = [
                    'business_category' => $categoryLabel,
                    'parent_reason' => $parentLabel,
                    'parent_reason_code' => $classification['parent_reason_code'],
                    'sub_reason' => $subReason,
                    'sub_reason_label' => $subLabel,
                    'line_count' => 0,
                    'order_ids' => [],
                    'undershipped_value' => 0.0,
                ];
            }
            $breakdownAcc[$breakdownKey]['line_count']++;
            $breakdownAcc[$breakdownKey]['order_ids'][(string) $orderId] = true;
            $breakdownAcc[$breakdownKey]['undershipped_value'] += $value;

            if ($classification['issue'] !== 'valid') {
                $flagged[] = [
                    'order_nbr' => $orderNbr,
                    'customer_acumatica_id' => $line->customer_acumatica_id ?? null,
                    'inventory_id' => $inventoryId,
                    'reason_code' => $reasonCode,
                    'issue' => $classification['issue'],
                    'business_category' => $categoryLabel,
                    'undershipped_value' => round($value, 2),
                ];
            }
        }

        $totalLines = count($shortfallLines);
        $captureRate = $totalLines > 0 ? round(($valid / $totalLines) * 1000) / 10 : null;

        $byCategory = [];
        foreach ($categoryBuckets as $key => $bucket) {
            $byCategory[$key] = [
                'business_category' => $key,
                'label' => $this->businessCategory->label($key),
                'line_count' => $bucket['line_count'],
                'order_count' => count($bucket['order_ids']),
                'undershipped_value' => round($bucket['undershipped_value'], 2),
                'valid_reason_lines' => $bucket['valid_reason_lines'],
                'missing_reason_lines' => $bucket['missing_reason_lines'],
                'unclassified_reason_lines' => $bucket['unclassified_reason_lines'],
            ];
        }

        $breakdown = collect($breakdownAcc)
            ->map(fn (array $row) => [
                'business_category' => $row['business_category'],
                'parent_reason' => $row['parent_reason'],
                'parent_reason_code' => $row['parent_reason_code'],
                'sub_reason' => $row['sub_reason'],
                'sub_reason_label' => $row['sub_reason_label'],
                'line_count' => $row['line_count'],
                'order_count' => count($row['order_ids']),
                'undershipped_value' => round($row['undershipped_value'], 2),
            ])
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();

        usort($flagged, fn ($a, $b) => $b['undershipped_value'] <=> $a['undershipped_value']);

        return [
            'summary' => [
                'total_shortfall_lines' => $totalLines,
                'total_shortfall_orders' => count($orderIds),
                'valid_reason_lines' => $valid,
                'missing_reason_lines' => $missing,
                'unclassified_reason_lines' => $unclassified,
                'capture_rate_pct' => $captureRate,
            ],
            'by_business_category' => $byCategory,
            'breakdown' => $breakdown,
            'flagged_records' => array_slice($flagged, 0, 100),
        ];
    }

    /**
     * @param  list<int>  $salesOrderIds
     * @return array<string, ?string>
     */
    public function productTypesForOrderLines(array $salesOrderIds): array
    {
        if ($salesOrderIds === []) {
            return [];
        }

        $inventoryIds = AcumaticaSalesOrderLine::query()
            ->whereIn('sales_order_id', $salesOrderIds)
            ->whereNotNull('inventory_id')
            ->distinct()
            ->pluck('inventory_id');

        return AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->pluck('product_type', 'inventory_id')
            ->all();
    }

    /** @return array<string, array{line_count: int, order_ids: array<string, bool>, undershipped_value: float, valid_reason_lines: int, missing_reason_lines: int, unclassified_reason_lines: int}> */
    private function emptyCategoryBuckets(): array
    {
        return [
            FillRateBusinessCategory::MANUFACTURED => [
                'line_count' => 0,
                'order_ids' => [],
                'undershipped_value' => 0.0,
                'valid_reason_lines' => 0,
                'missing_reason_lines' => 0,
                'unclassified_reason_lines' => 0,
            ],
            FillRateBusinessCategory::TRADING => [
                'line_count' => 0,
                'order_ids' => [],
                'undershipped_value' => 0.0,
                'valid_reason_lines' => 0,
                'missing_reason_lines' => 0,
                'unclassified_reason_lines' => 0,
            ],
        ];
    }

    private function emptyReport(): array
    {
        return [
            'summary' => [
                'total_shortfall_lines' => 0,
                'total_shortfall_orders' => 0,
                'valid_reason_lines' => 0,
                'missing_reason_lines' => 0,
                'unclassified_reason_lines' => 0,
                'capture_rate_pct' => null,
            ],
            'by_business_category' => [
                FillRateBusinessCategory::MANUFACTURED => [
                    'business_category' => FillRateBusinessCategory::MANUFACTURED,
                    'label' => FillRateBusinessCategory::LABEL_MANUFACTURED,
                    'line_count' => 0,
                    'order_count' => 0,
                    'undershipped_value' => 0.0,
                    'valid_reason_lines' => 0,
                    'missing_reason_lines' => 0,
                    'unclassified_reason_lines' => 0,
                ],
                FillRateBusinessCategory::TRADING => [
                    'business_category' => FillRateBusinessCategory::TRADING,
                    'label' => FillRateBusinessCategory::LABEL_TRADING,
                    'line_count' => 0,
                    'order_count' => 0,
                    'undershipped_value' => 0.0,
                    'valid_reason_lines' => 0,
                    'missing_reason_lines' => 0,
                    'unclassified_reason_lines' => 0,
                ],
            ],
            'breakdown' => [],
            'flagged_records' => [],
        ];
    }
}