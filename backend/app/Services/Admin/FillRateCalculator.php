<?php

namespace App\Services\Admin;

class FillRateCalculator
{
    private const NA_STATUSES = ['On Hold', 'Pending Approval'];

    /**
     * Compute order-level fill rate from line items, rolling up duplicate SKUs first.
     *
     * @param  list<array{inventory_id?: ?string, order_qty: float, shipped_qty: float, qty_at_approval?: float, unit_price?: float}>  $lines
     * @return array{
     *   fill_rate_pct: ?float,
     *   fill_rate_status: string,
     *   total_ordered_qty: float,
     *   total_shipped_qty: float,
     *   unique_item_count: int,
     *   revenue_not_shipped: float
     * }
     */
    public function compute(string $status, array $lines): array
    {
        if (in_array($status, self::NA_STATUSES, true)) {
            return $this->naResult();
        }

        $bySku = $this->rollupByInventoryId($lines);

        if ($bySku === []) {
            return $this->naResult();
        }

        $totalOrdered = 0.0;
        $totalShipped = 0.0;
        $revenueNotShipped = 0.0;

        foreach ($bySku as $rollup) {
            $approved = $rollup['qty_at_approval'];
            $shipped = min($rollup['shipped_qty'], $approved);

            if ($approved <= 0) {
                continue;
            }

            $totalOrdered += $approved;
            $totalShipped += $shipped;

            if ($rollup['unit_price'] > 0) {
                $revenueNotShipped += max(0, $approved - $shipped) * $rollup['unit_price'];
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
            'fill_rate_pct'       => $pct,
            'fill_rate_status'    => $this->thresholdStatus($pct),
            'total_ordered_qty'   => $totalOrdered,
            'total_shipped_qty'   => $totalShipped,
            'unique_item_count'   => count($bySku),
            'revenue_not_shipped' => round($revenueNotShipped, 2),
        ];
    }

    /**
     * @param  list<array{inventory_id?: ?string, order_qty: float, shipped_qty: float, qty_at_approval?: float, unit_price?: float}>  $lines
     * @return array<string, array{order_qty: float, qty_at_approval: float, shipped_qty: float, unit_price: float}>
     */
    private function rollupByInventoryId(array $lines): array
    {
        $bySku = [];

        foreach ($lines as $line) {
            $ordered = (float) ($line['order_qty'] ?? 0);
            $approved = (float) ($line['qty_at_approval'] ?? 0);
            if ($approved <= 0) {
                $approved = $ordered;
            }
            if ($approved <= 0) {
                continue;
            }

            $sku = trim((string) ($line['inventory_id'] ?? ''));
            if ($sku === '') {
                $sku = '__line_'.count($bySku);
            }

            if (! isset($bySku[$sku])) {
                $bySku[$sku] = [
                    'order_qty'        => 0.0,
                    'qty_at_approval'  => 0.0,
                    'shipped_qty'      => 0.0,
                    'unit_price'       => 0.0,
                ];
            }

            $bySku[$sku]['order_qty'] += $ordered;
            $bySku[$sku]['qty_at_approval'] += $approved;
            $bySku[$sku]['shipped_qty'] += (float) ($line['shipped_qty'] ?? 0);

            $price = (float) ($line['unit_price'] ?? 0);
            if ($price > $bySku[$sku]['unit_price']) {
                $bySku[$sku]['unit_price'] = $price;
            }
        }

        return $bySku;
    }

    private function naResult(): array
    {
        return [
            'fill_rate_pct'       => null,
            'fill_rate_status'    => 'na',
            'total_ordered_qty'   => 0,
            'total_shipped_qty'   => 0,
            'unique_item_count'   => 0,
            'revenue_not_shipped' => 0,
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
}