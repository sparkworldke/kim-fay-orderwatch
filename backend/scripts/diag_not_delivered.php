<?php

/**
 * Diagnostic for the "Products Not Delivered" page.
 * Mirrors ItemsNotDeliveredController::rows() and reports why rows may be empty.
 *
 * Usage: php scripts/diag_not_delivered.php [YYYY-MM-DD] [YYYY-MM-DD]
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AcumaticaSalesOrder;

$from = isset($argv[1]) ? \Carbon\Carbon::parse($argv[1])->startOfDay() : now()->startOfMonth();
$to = isset($argv[2]) ? \Carbon\Carbon::parse($argv[2])->endOfDay() : now();

echo "Period: {$from->toDateString()} -> {$to->toDateString()}\n";

echo "\n===== DB OVERVIEW (no filters) =====\n";
$totalOrders = \DB::table('acumatica_sales_orders')->count();
$totalLines = \DB::table('acumatica_sales_order_lines')->count();
$boLines = \DB::table('acumatica_backorder_lines')->count();
$items = \DB::table('acumatica_inventory_items')->count();
$custs = \DB::table('acumatica_customers')->count();
echo "acumatica_sales_orders total rows: {$totalOrders}\n";
echo "acumatica_sales_order_lines total rows: {$totalLines}\n";
echo "acumatica_backorder_lines total rows: {$boLines}\n";
echo "acumatica_inventory_items total rows: {$items}\n";
echo "acumatica_customers total rows: {$custs}\n";
echo "\n===== acumatica_backorder_lines shape (candidate source) =====\n";
if ($boLines) {
    $kinds = \DB::table('acumatica_backorder_lines')->select('shortfall_kind', \DB::raw('count(*) c'))->groupBy('shortfall_kind')->pluck('c', 'shortfall_kind')->all();
    echo "shortfall_kind: " . json_encode($kinds) . "\n";
    $statuses = \DB::table('acumatica_backorder_lines')->select('order_status', \DB::raw('count(*) c'))->groupBy('order_status')->pluck('c', 'order_status')->all();
    echo "order_status: " . json_encode($statuses) . "\n";
    // ordered > delivered breakdown using backorder_lines
    $rows = \DB::table('acumatica_backorder_lines')->limit(5000)->get();
    $deliveredPositive = 0; $openPositive = 0;
    foreach ($rows as $r) {
        $delivered = max((float)($r->shipped_qty ?? 0), (float)($r->qty_on_shipments ?? 0), (float)($r->invoiced_qty ?? 0));
        if ((float)($r->order_qty ?? 0) - $delivered > 0) $deliveredPositive++;
        if ((float)($r->open_qty ?? 0) > 0) $openPositive++;
    }
    echo "backorder_lines sampled={$rows->count()} | (order - max(shipped,on_ship,invoiced))>0: {$deliveredPositive} | open_qty>0: {$openPositive}\n";
    $sample = \DB::table('acumatica_backorder_lines')->limit(5)->get(['order_nbr','order_status','inventory_id','order_qty','shipped_qty','qty_on_shipments','invoiced_qty','open_qty','unit_price','revenue_at_risk','warehouse_id']);
    foreach ($sample as $s) { echo json_encode($s) . "\n"; }
}
if ($totalOrders) {
    $typeDist = \DB::table('acumatica_sales_orders')->select('order_type', \DB::raw('count(*) as c'))->groupBy('order_type')->pluck('c', 'order_type')->all();
    echo "order_type distribution: " . json_encode($typeDist) . "\n";
    $bounds = \DB::table('acumatica_sales_orders')->selectRaw('min(order_date) as mn, max(order_date) mx')->first();
    echo "order_date range: " . ($bounds->mn ?? 'n/a') . " -> " . ($bounds->mx ?? 'n/a') . "\n";
    $statusDist = \DB::table('acumatica_sales_orders')->select('status', \DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status')->all();
    echo "status distribution: " . json_encode($statusDist) . "\n";
}
echo "\n";

$query = AcumaticaSalesOrder::query()
    ->join('acumatica_sales_order_lines as line', 'line.sales_order_id', '=', 'acumatica_sales_orders.id')
    ->where('acumatica_sales_orders.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
    ->whereBetween('acumatica_sales_orders.order_date', [$from, $to])
    ->select([
        'acumatica_sales_orders.acumatica_order_nbr as order_nbr',
        'acumatica_sales_orders.order_date',
        'acumatica_sales_orders.status as order_status',
        'line.inventory_id',
        'line.order_qty',
        'line.shipped_qty',
        'line.qty_on_shipments',
        'line.open_qty',
        'line.cancelled_qty',
        'line.unit_price',
    ]);

$lines = $query->orderBy('acumatica_sales_orders.order_date')->get();
echo "Total SO-type lines in period: {$lines->count()}\n";

$statuses = $lines->pluck('order_status')->map(fn ($s) => (string) ($s ?? 'NULL'))->countBy()->all();
echo "Order statuses: " . json_encode($statuses) . "\n\n";

$controllerPositive = 0;   // max(0, order - max(shipped, on_shipments))
$shippedOnlyPositive = 0;  // max(0, order - shipped_qty)
$onShipmentsPositive = 0;  // max(0, order - qty_on_shipments)
$openQtyPositive = 0;      // open_qty > 0

$nullShipped = 0;
$nullOnShipments = 0;
$zeroShipped = 0;
$zeroOnShipments = 0;

$samples = [];

foreach ($lines as $line) {
    $order = (float) $line->order_qty;
    $shipped = (float) $line->shipped_qty;
    $onShipments = (float) $line->qty_on_shipments;
    $open = (float) ($line->open_qty ?? 0);

    if ($line->shipped_qty === null) $nullShipped++;
    if ($line->qty_on_shipments === null) $nullOnShipments++;
    if ($line->shipped_qty !== null && (float) $line->shipped_qty === 0.0) $zeroShipped++;
    if ($line->qty_on_shipments !== null && (float) $line->qty_on_shipments === 0.0) $zeroOnShipments++;

    $cShip = max($shipped, $onShipments);
    $controllerMissing = max(0, $order - $cShip);
    $shippedMissing = max(0, $order - $shipped);
    $onShipMissing = max(0, $order - $onShipments);

    if ($controllerMissing > 0) $controllerPositive++;
    if ($shippedMissing > 0) $shippedOnlyPositive++;
    if ($onShipMissing > 0) $onShipmentsPositive++;
    if ($open > 0) $openQtyPositive++;

    // Capture samples where controller says 0 but shipped-only/on-shipments-only say > 0
    if ($controllerMissing <= 0 && ($shippedMissing > 0 || $onShipMissing > 0) && count($samples) < 12) {
        $samples[] = [
            'order_nbr' => $line->order_nbr,
            'status' => $line->order_status,
            'inv' => $line->inventory_id,
            'order' => $order,
            'shipped' => $shipped,
            'on_shipments' => $onShipments,
            'open' => $open,
            'controller_missing' => $controllerMissing,
            'shipped_only_missing' => $shippedMissing,
            'on_shipments_only_missing' => $onShipMissing,
        ];
    }
}

echo "===== MISSING QTY BREAKDOWN (lines with value > 0) =====\n";
echo "Controller  max(0, order - max(shipped, on_shipments)): {$controllerPositive}\n";
echo "ShippedOnly max(0, order - shipped_qty)              : {$shippedOnlyPositive}\n";
echo "OnShipOnly  max(0, order - qty_on_shipments)         : {$onShipmentsPositive}\n";
echo "OpenQty>0                                            : {$openQtyPositive}\n\n";

echo "===== FIELD NULL/ZERO STATS =====\n";
echo "shipped_qty NULL      : {$nullShipped}\n";
echo "shipped_qty == 0      : {$zeroShipped}\n";
echo "qty_on_shipments NULL : {$nullOnShipments}\n";
echo "qty_on_shipments == 0 : {$zeroOnShipments}\n\n";

echo "===== SAMPLES where controller = 0 but a single shipped source shows a shortfall =====\n";
foreach ($samples as $s) {
    echo json_encode($s) . "\n";
}
if (!$samples) {
    echo "(none — controller and single-source agree, so the shortfall logic is not the cause)\n";
}
