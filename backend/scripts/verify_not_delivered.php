<?php

/**
 * Verification harness for the "Products Not Delivered" page.
 *
 * Mirrors the CURRENT ItemsNotDeliveredController::rows() implementation
 * (sourced from acumatica_backorder_lines, ordered - delivered basis) and
 * reports whether the page would now return rows.
 *
 * Usage: php scripts/verify_not_delivered.php [YYYY-MM-DD] [YYYY-MM-DD]
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Models\InventoryWarehouseBalance;
use Carbon\Carbon;

$from = isset($argv[1]) ? Carbon::parse($argv[1])->startOfDay() : Carbon::parse('2020-01-01')->startOfDay();
$to = isset($argv[2]) ? Carbon::parse($argv[2])->endOfDay() : Carbon::parse('2030-12-31')->endOfDay();

echo "Period: {$from->toDateString()} -> {$to->toDateString()}\n";

$dateExpr = 'COALESCE(DATE(aso.order_date), acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(acumatica_backorder_lines.synced_at))';

$query = AcumaticaBackorderLine::query()
    ->leftJoin('acumatica_inventory_items as item', 'item.inventory_id', '=', 'acumatica_backorder_lines.inventory_id')
    ->leftJoin('acumatica_sales_orders as aso', function ($join) {
        $join->on('acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr')
            ->where('aso.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER);
    })
    ->leftJoin('acumatica_customers as customer', 'customer.acumatica_id', '=', 'acumatica_backorder_lines.customer_acumatica_id')
    ->leftJoin('acumatica_customers as parent', 'parent.acumatica_id', '=', 'customer.parent_acumatica_id')
    ->whereRaw($dateExpr . ' >= ?', [$from->toDateString()])
    ->whereRaw($dateExpr . ' <= ?', [$to->toDateString()])
    ->select([
        'acumatica_backorder_lines.order_nbr',
        'aso.order_date',
        'acumatica_backorder_lines.order_status',
        'acumatica_backorder_lines.customer_acumatica_id as customer_id',
        'acumatica_backorder_lines.customer_name',
        'acumatica_backorder_lines.reason_code as unfilled_reason_code',
        'acumatica_backorder_lines.inventory_id',
        'acumatica_backorder_lines.order_qty',
        'acumatica_backorder_lines.shipped_qty',
        'acumatica_backorder_lines.qty_on_shipments',
        'acumatica_backorder_lines.invoiced_qty',
        'acumatica_backorder_lines.unit_price',
        'item.description as item_description',
        'item.brand',
    ])
    ->addSelect(\DB::raw($dateExpr . ' as effective_date'));

$lines = $query->orderByRaw($dateExpr)->get();

echo "Raw lines matched by query (period-scoped): " . $lines->count() . "\n";

$notDeliveredRows = [];
$zeroRows = 0;
foreach ($lines as $line) {
    $delivered = max(
        (float) $line->shipped_qty,
        (float) $line->qty_on_shipments,
        (float) ($line->invoiced_qty ?? 0),
    );
    $notDelivered = max(0, (float) $line->order_qty - $delivered);
    if ($notDelivered > 0) {
        $line->delivered = $delivered;
        $line->not_delivered = $notDelivered;
        $notDeliveredRows[] = $line;
    } else {
        $zeroRows++;
    }
}

echo "Rows kept (not_delivered_qty > 0): " . count($notDeliveredRows) . "\n";
echo "Rows dropped (fully delivered / over-delivered): {$zeroRows}\n";

if ($notDeliveredRows === []) {
    echo "\nRESULT: page would still be EMPTY.\n";
    exit(1);
}

$skus = array_unique(array_map(fn ($r) => $r->inventory_id, $notDeliveredRows));
$outlets = array_unique(array_map(fn ($r) => $r->customer_id, $notDeliveredRows));
$orders = array_unique(array_map(fn ($r) => $r->order_nbr, $notDeliveredRows));
$units = array_sum(array_map(fn ($r) => $r->not_delivered, $notDeliveredRows));
$amount = array_sum(array_map(fn ($r) => $r->not_delivered * (float) $r->unit_price, $notDeliveredRows));

echo "\n===== SUMMARY (matches controller response shape) =====\n";
echo "affected_skus:        " . count($skus) . "\n";
echo "affected_outlets:     " . count($outlets) . "\n";
echo "affected_orders:      " . count($orders) . "\n";
echo "not_delivered_units:  " . round($units, 4) . "\n";
echo "not_delivered_amount: " . round($amount, 2) . "\n";

echo "\n===== SAMPLE ROWS (up to 10) =====\n";
foreach (array_slice($notDeliveredRows, 0, 10) as $r) {
    echo json_encode([
        'order_nbr' => $r->order_nbr,
        'effective_date' => $r->effective_date,
        'order_status' => $r->order_status,
        'customer_id' => $r->customer_id,
        'customer_name' => $r->customer_name,
        'inventory_id' => $r->inventory_id,
        'product_name' => $r->item_description ?: $r->inventory_id,
        'brand' => $r->brand,
        'order_qty' => (float) $r->order_qty,
        'delivered' => $r->delivered,
        'not_delivered' => $r->not_delivered,
        'unit_price' => (float) $r->unit_price,
        'not_delivered_amount' => round($r->not_delivered * (float) $r->unit_price, 2),
        'reason_code' => $r->unfilled_reason_code,
    ]) . "\n";
}

// Confirm the inventory balances lookup (warehouse_stocks) resolves for at least one SKU.
$stockSample = InventoryWarehouseBalance::query()
    ->whereIn('inventory_id', $skus)
    ->limit(5)
    ->get(['inventory_id', 'warehouse_id', 'qty_available', 'qty_on_hand']);
echo "\n===== inventory balance sample =====\n";
foreach ($stockSample as $s) {
    echo json_encode($s) . "\n";
}

echo "\nRESULT: page now returns " . count($notDeliveredRows) . " not-delivered lines across " . count($skus) . " SKU(s).\n";
