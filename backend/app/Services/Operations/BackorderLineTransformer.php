<?php

namespace App\Services\Operations;

use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BackorderLineTransformer
{
    public function __construct(
        private readonly OperationsCatalogResolver $catalog,
        private readonly FillRateBusinessCategory $businessCategory,
    ) {}

    /** Enrich line-shaped query results using one set of batched catalog lookups. */
    public function transform(Collection $lines): Collection
    {
        $inventoryIds = $lines->pluck('inventory_id')->all();
        $descriptions = $this->catalog->descriptionsForInventoryIds($inventoryIds);
        $classifications = $this->catalog->classificationsForInventoryIds($inventoryIds);
        $stock = $this->catalog->stockForInventoryIds($inventoryIds);
        $stockByWarehouse = $this->catalog->stockForInventoryIdsByWarehouse($inventoryIds);
        $customerNames = $this->catalog->namesForCustomerIds($lines->pluck('customer_acumatica_id')->all());

        return $lines->transform(function ($line) use ($descriptions, $classifications, $stock, $stockByWarehouse, $customerNames) {
            $line->product_name = $this->catalog->resolveProductName($line->inventory_id, null, $descriptions);
            foreach ($this->catalog->classificationFieldsFor($line->inventory_id, $classifications) as $field => $value) {
                $line->{$field} = $value;
            }
            $line->product_segment = $this->businessCategory->classify(
                $line->inventory_id,
                $line->product_type ?? null,
            );
            $line->product_segment_label = $this->businessCategory->label($line->product_segment);
            $line->customer_name = $this->catalog->resolveCustomerName(
                $line->customer_name,
                $line->customer_acumatica_id,
                $customerNames,
            );
            $line->uom = $this->catalog->resolveUom($line->uom, $line->inventory_id, $stock);
            $line->order_date = $this->dateString($line->order_date ?? null);

            $storedStatus = is_string($line->order_status ?? null) ? trim($line->order_status) : '';
            $salesOrderStatus = is_string($line->sales_order_status ?? null) ? trim($line->sales_order_status) : '';
            $line->order_status = $storedStatus !== '' ? $storedStatus : ($salesOrderStatus !== '' ? $salesOrderStatus : null);
            unset($line->sales_order_status);

            $line->reason_code = $this->effectiveReason($line->reason_code ?? null, $line->so_line_reason_code ?? null);
            unset($line->so_line_reason_code);

            $requestedWarehouse = strtoupper(trim((string) ($line->warehouse_id ?: 'FGS')));
            $inventoryStock = $stockByWarehouse->get($line->inventory_id)?->get($requestedWarehouse)
                ?? $stock->get($line->inventory_id);
            $line->stock_warehouse_id = $inventoryStock['warehouse_id'] ?? $requestedWarehouse;
            $line->fgs_qty_on_hand = $inventoryStock['qty_on_hand'] ?? null;
            $line->fgs_qty_available = $inventoryStock['qty_available'] ?? null;
            $line->fgs_synced_at = $inventoryStock['synced_at'] ?? null;
            // Backward-compatible aliases for existing UI consumers.
            $line->qty_on_hand = $line->fgs_qty_on_hand;
            $line->qty_available = $line->fgs_qty_available;

            $orderQty = max(0, (float) ($line->order_qty ?? 0));
            $cancelledQty = max(0, (float) ($line->cancelled_qty ?? 0));
            $shippedQty = max(0, (float) ($line->shipped_qty ?? 0));
            $qtyOnShipments = max(0, (float) ($line->qty_on_shipments ?? 0));
            $storedOpenQty = max(0, (float) ($line->open_qty ?? 0));
            $storedBackorderQty = max(0, (float) ($line->backorder_qty ?? 0));
            $unitPrice = max(0, (float) ($line->unit_price ?? 0));
            $netOrderQty = max(0, $orderQty - $cancelledQty);
            $deliveredQty = SalesOrderLineFulfillmentDeriver::deliveredQty($shippedQty, $qtyOnShipments);
            // Prefer Acumatica residual open_qty; never open − qty_on_shipments again.
            $openQty = SalesOrderLineFulfillmentDeriver::residualOpenQty(
                $orderQty,
                $shippedQty,
                $qtyOnShipments,
                $cancelledQty,
                $storedOpenQty > 0 ? $storedOpenQty : null,
            );
            $backorderQty = $storedBackorderQty > 0 ? $storedBackorderQty : $openQty;

            $line->net_order_qty = round($netOrderQty, 4);
            $line->open_qty = round($openQty, 4);
            $line->committed_qty = round($qtyOnShipments, 4);
            $line->backorder_qty = round($backorderQty, 4);
            $line->revenue_at_risk = SalesOrderLineFulfillmentDeriver::openLineValue($openQty, $unitPrice);
            $line->fill_rate_pct = $netOrderQty > 0
                ? round(min($deliveredQty, $netOrderQty) / $netOrderQty * 100, 2)
                : null;

            $onHand = $line->fgs_qty_on_hand !== null ? (float) $line->fgs_qty_on_hand : null;
            $line->stock_covers_open = $onHand !== null && $onHand >= $openQty;
            $line->stock_shortfall = $onHand !== null && $onHand < $openQty;
            $line->stock_signal = $onHand === null ? 'unknown'
                : ($onHand <= 0 ? 'true_stockout'
                    : ($onHand < $openQty ? 'partial_cover' : 'stock_available_not_shipped'));
            $line->suggested_reason_family = $this->suggestedReasonFamily(
                $line->product_segment,
                $onHand,
                $line->warehouse_id ?? null,
                (bool) $line->stock_covers_open,
            );
            $line->reason_stock_mismatch = $this->reasonStockMismatch(
                $line->reason_code,
                $line->product_segment,
                $onHand,
                (bool) $line->stock_covers_open,
            );
            $status = strtolower(trim((string) ($line->order_status ?? '')));
            $line->is_excluded_from_kpi = $unitPrice <= 0
                || in_array($status, ['rejected', 'on hold', 'pending approval'], true);

            $age = $line->first_backordered_at instanceof CarbonInterface
                ? (int) max(0, $line->first_backordered_at->copy()->startOfDay()->diffInDays(now()->startOfDay()))
                : null;
            $line->backorder_age_days = $age;
            $line->aging_bucket = $this->agingBucket($age);
            $line->missing_reason_exception = $age !== null
                && $age > max(0, (int) config('backorders.missing_reason_exception_days', 7))
                && $line->reason_code === null;

            return $line;
        });
    }

    public function effectiveReason(mixed $stored, mixed $salesOrderLine): ?string
    {
        foreach ([$stored, $salesOrderLine] as $candidate) {
            $value = is_string($candidate) ? trim($candidate) : '';
            if ($value !== '' && strcasecmp($value, 'unassigned') !== 0) {
                return $value;
            }
        }

        return null;
    }

    public function agingBucket(?int $age): ?string
    {
        if ($age === null) return null;
        if ($age <= 7) return '0-7';
        if ($age <= 14) return '8-14';
        if ($age <= 30) return '15-30';
        return '30+';
    }

    public function suggestedReasonFamily(
        string $productSegment,
        ?float $onHand,
        ?string $warehouseId,
        bool $coversOpen,
    ): string {
        if (strcasecmp((string) $warehouseId, 'MSA') === 0 && ! $coversOpen) return 'LOGISTICS';
        if ($onHand === null) return 'UNCLASSIFIED';
        if ($coversOpen) return 'LOGISTICS';
        return $productSegment === FillRateBusinessCategory::MANUFACTURED ? 'PRODUCTION' : 'PROCUREMENT';
    }

    public function reasonStockMismatch(
        ?string $reasonCode,
        string $productSegment,
        ?float $onHand,
        bool $coversOpen,
    ): bool {
        $reason = strtolower(trim((string) $reasonCode));
        if ($reason === '' || $onHand === null) return false;
        $stockoutReason = str_contains($reason, 'out_of_stock') || str_contains($reason, 'stockout');
        $logisticsReason = in_array($reason, [
            'did_not_pick_on_shipment', 'truck_full', 'transfer_delays', 'delay_in_delivery',
        ], true);
        if ($coversOpen && $stockoutReason) return true;
        if ($onHand <= 0 && $logisticsReason) return true;
        return $productSegment === FillRateBusinessCategory::TRADING
            && (str_contains($reason, 'production') || str_contains($reason, 'raw_material'));
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) return $value->toDateString();
        if (! is_string($value) || trim($value) === '') return null;

        return substr(trim($value), 0, 10);
    }
}
