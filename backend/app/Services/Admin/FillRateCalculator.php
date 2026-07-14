<?php

namespace App\Services\Admin;

class FillRateCalculator
{
    /**
     * Fill rate is only meaningful for completed sales orders.
     * Formula: (Shipped Qty ÷ Order Qty) × 100
     */
    public const ELIGIBLE_STATUSES = ['completed'];

    /**
     * Sales-segment labels used across the fill-rate dashboard.
     * KP  = Kimfay Professional (customer_class starts with "KP").
     * CS  = Consumer Sales (all remaining customer classes).
     */
    public const SEGMENT_KP = 'KP';
    public const SEGMENT_CS = 'CS';
    public const SEGMENT_KP_LABEL = 'KP (Kimfay Professional)';
    public const SEGMENT_CS_LABEL = 'CS (Consumer Sales)';

    public function segmentLabel(string $segment): string
    {
        return $segment === self::SEGMENT_KP
            ? self::SEGMENT_KP_LABEL
            : self::SEGMENT_CS_LABEL;
    }

    public static function isEligibleStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::ELIGIBLE_STATUSES, true);
    }

    /**
     * Compute order-level fill rate from line items, rolling up duplicate SKUs first.
     *
     * Formula (Completed orders only):
     *   Fill Rate % = (Shipped Qty ÷ Order Qty) × 100
     *
     * Shipped qty prefers `shipped_qty`, falling back to `qty_on_shipments`.
     * Non-completed statuses return N/A.
     *
     * @param  list<array{
     *   inventory_id?: ?string,
     *   order_qty: float,
     *   qty_on_shipments?: float,
     *   shipped_qty?: float,
     *   qty_at_approval?: float,
     *   unit_price?: float,
     *   unfilled_reason_code?: ?string,
     *   is_out_of_stock?: bool
     * }>  $lines
     * @param  bool  $includeOutOfStock  When false, OOS lines are excluded from the fill-rate math
     * @return array{
     *   fill_rate_pct: ?float,
     *   fill_rate_status: string,
     *   total_ordered_qty: float,
     *   total_shipped_qty: float,
     *   unique_item_count: int,
     *   revenue_not_shipped: float,
     *   out_of_stock_line_count: int
     * }
     */
    public function compute(string $status, array $lines, bool $includeOutOfStock = true): array
    {
        if (! self::isEligibleStatus($status)) {
            return $this->naResult();
        }

        if (! $includeOutOfStock) {
            $lines = array_values(array_filter(
                $lines,
                static function (array $line): bool {
                    if (array_key_exists('is_out_of_stock', $line)) {
                        return ! (bool) $line['is_out_of_stock'];
                    }

                    $reason = $line['unfilled_reason_code'] ?? null;

                    return ! \App\Services\Operations\SalesOrderReasonCatalog::isOutOfStockReason(
                        is_string($reason) ? $reason : null,
                    );
                },
            ));
        }

        $bySku = $this->rollupByInventoryId($lines);

        if ($bySku === []) {
            return $this->naResult();
        }

        $totalOrdered = 0.0;
        $totalShipped = 0.0;
        $revenueNotShipped = 0.0;
        $outOfStockLines = 0;

        foreach ($bySku as $rollup) {
            $ordered = $rollup['order_qty'];
            if ($ordered <= 0) {
                continue;
            }

            // Cap shipped at ordered so over-shipments do not exceed 100% fill.
            $shipped = min($rollup['shipped_qty'], $ordered);

            $totalOrdered += $ordered;
            $totalShipped += $shipped;

            if ($rollup['shipped_qty'] <= 0) {
                $outOfStockLines++;
            }

            if ($rollup['unit_price'] > 0) {
                $revenueNotShipped += max(0, $ordered - $shipped) * $rollup['unit_price'];
            }
        }

        if ($totalOrdered <= 0) {
            return $this->naResult();
        }

        $pct = SalesOrderLineFulfillmentDeriver::safeFillRate($totalShipped, $totalOrdered);
        if ($pct === null) {
            return $this->naResult();
        }

        return [
            'fill_rate_pct'           => $pct,
            'fill_rate_status'        => $this->thresholdStatus($pct),
            'total_ordered_qty'       => $totalOrdered,
            'total_shipped_qty'       => $totalShipped,
            'unique_item_count'       => count($bySku),
            'revenue_not_shipped'     => round($revenueNotShipped, 2),
            'out_of_stock_line_count' => $outOfStockLines,
        ];
    }

    /**
     * @param  list<array{inventory_id?: ?string, order_qty: float, qty_on_shipments?: float, shipped_qty?: float, unit_price?: float}>  $lines
     * @return array<string, array{order_qty: float, shipped_qty: float, unit_price: float}>
     */
    private function rollupByInventoryId(array $lines): array
    {
        $bySku = [];

        foreach ($lines as $line) {
            $ordered = (float) ($line['order_qty'] ?? 0);
            if ($ordered <= 0) {
                continue;
            }

            $shipped = $this->resolveShippedQty($line);

            $sku = trim((string) ($line['inventory_id'] ?? ''));
            if ($sku === '') {
                $sku = '__line_'.count($bySku);
            }

            if (! isset($bySku[$sku])) {
                $bySku[$sku] = [
                    'order_qty'   => 0.0,
                    'shipped_qty' => 0.0,
                    'unit_price'  => 0.0,
                ];
            }

            $bySku[$sku]['order_qty'] += $ordered;
            $bySku[$sku]['shipped_qty'] += $shipped;

            $price = (float) ($line['unit_price'] ?? 0);
            if ($price > $bySku[$sku]['unit_price']) {
                $bySku[$sku]['unit_price'] = $price;
            }
        }

        return $bySku;
    }

    /**
     * Prefer explicit shipped_qty; fall back to qty_on_shipments (Acumatica QtyOnShipments).
     *
     * @param  array<string, mixed>  $line
     */
    private function resolveShippedQty(array $line): float
    {
        if (array_key_exists('shipped_qty', $line) && $line['shipped_qty'] !== null && $line['shipped_qty'] !== '') {
            return (float) $line['shipped_qty'];
        }

        return (float) ($line['qty_on_shipments'] ?? 0);
    }

    private function naResult(): array
    {
        return [
            'fill_rate_pct'           => null,
            'fill_rate_status'        => 'na',
            'total_ordered_qty'       => 0,
            'total_shipped_qty'       => 0,
            'unique_item_count'       => 0,
            'revenue_not_shipped'     => 0,
            'out_of_stock_line_count' => 0,
        ];
    }

    public function thresholdStatus(float $pct): string
    {
        if ($pct >= 95) {
            return 'healthy';
        }

        if ($pct >= 80) {
            return 'at_risk';
        }

        return 'critical';
    }

    /**
     * Classify a customer into the KP (Kimfay Professional) or CS (Consumer Sales)
     * sales segment based on the customer_class field.
     *
     * Rules:
     *  - KP: customer_class starts with "KP" (case-insensitive, whitespace-trimmed).
     *  - CS: everything else (including null/empty customer_class).
     */
    public function segmentForCustomerClass(?string $customerClass): string
    {
        $normalized = strtoupper(trim((string) $customerClass));

        return str_starts_with($normalized, 'KP')
            ? self::SEGMENT_KP
            : self::SEGMENT_CS;
    }

    /**
     * Build fill-rate split metrics for the KP / CS sales segments.
     *
     * @param  iterable  $snapshots  AcumaticaFillRateSnapshot collection
     * @param  array<string,string>  $customerClasses  Map of customer_acumatica_id => customer_class
     * @return array{
     *   KP: array{fill_rate_pct: ?float, status: string, order_count: int, total_ordered_qty: float, total_shipped_qty: float, revenue_not_shipped: float, healthy_count: int, at_risk_count: int, critical_count: int},
     *   CS: array{fill_rate_pct: ?float, status: string, order_count: int, total_ordered_qty: float, total_shipped_qty: float, revenue_not_shipped: float, healthy_count: int, at_risk_count: int, critical_count: int}
     * }
     */
    public function segmentSplit(iterable $snapshots, array $customerClasses): array
    {
        $buckets = [
            self::SEGMENT_KP => $this->emptySegmentBucket(),
            self::SEGMENT_CS => $this->emptySegmentBucket(),
        ];

        foreach ($snapshots as $snapshot) {
            $class = $customerClasses[$snapshot->customer_acumatica_id] ?? null;
            $segment = $this->segmentForCustomerClass($class);
            $bucket = &$buckets[$segment];

            $bucket['order_count']++;

            if ($snapshot->fill_rate_status === 'na') {
                continue;
            }

            $bucket['total_ordered_qty'] += (float) $snapshot->total_ordered_qty;
            $bucket['total_shipped_qty'] += (float) $snapshot->total_shipped_qty;
            $bucket['revenue_not_shipped'] += (float) $snapshot->revenue_not_shipped;

            if ($snapshot->fill_rate_status === 'healthy') {
                $bucket['healthy_count']++;
            } elseif ($snapshot->fill_rate_status === 'at_risk') {
                $bucket['at_risk_count']++;
            } elseif ($snapshot->fill_rate_status === 'critical') {
                $bucket['critical_count']++;
            }
        }

        foreach ($buckets as $seg => $data) {
            $pct = $data['total_ordered_qty'] > 0
                ? round(($data['total_shipped_qty'] / $data['total_ordered_qty']) * 1000) / 10
                : null;

            $buckets[$seg]['fill_rate_pct'] = $pct;
            $buckets[$seg]['status'] = $pct !== null ? $this->thresholdStatus($pct) : 'na';
            $buckets[$seg]['revenue_not_shipped'] = round($data['revenue_not_shipped'], 2);
            $buckets[$seg]['total_ordered_qty'] = round($data['total_ordered_qty'], 4);
            $buckets[$seg]['total_shipped_qty'] = round($data['total_shipped_qty'], 4);
        }

        return $buckets;
    }

    /**
     * @return array{fill_rate_pct: null, status: string, order_count: int, total_ordered_qty: float, total_shipped_qty: float, revenue_not_shipped: float, healthy_count: int, at_risk_count: int, critical_count: int}
     */
    private function emptySegmentBucket(): array
    {
        return [
            'fill_rate_pct'       => null,
            'status'              => 'na',
            'order_count'         => 0,
            'total_ordered_qty'   => 0.0,
            'total_shipped_qty'   => 0.0,
            'revenue_not_shipped' => 0.0,
            'healthy_count'       => 0,
            'at_risk_count'       => 0,
            'critical_count'      => 0,
        ];
    }
}
