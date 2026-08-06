<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$nbr = strtoupper(trim($argv[1] ?? 'SO359099'));
$client = app(App\Services\Admin\AcumaticaClient::class);

echo "Fetching {$nbr} from Acumatica...\n";
$orders = $client->fetchSalesOrdersByNumbers([$nbr]);
$path = storage_path("app/{$nbr}-raw.json");
file_put_contents($path, json_encode($orders, JSON_PRETTY_PRINT));
echo "Saved: {$path}\n";
echo "orders_count=" . count($orders) . "\n";

if ($orders === []) {
    echo "NOT FOUND\n";
    exit(1);
}

$o = $orders[0];
$headerFields = [
    'OrderNbr', 'OrderType', 'Status', 'Date', 'RequestOn', 'ShipVia',
    'CustomerID', 'CustomerName', 'OrderTotal', 'CuryOrderTotal',
    'OrderedQty', 'OpenQty', 'ShippedQty', 'Completed',
    'LastModifiedDateTime', 'Hold', 'Approved',
];
echo "\n=== HEADER ===\n";
foreach ($headerFields as $f) {
    if (array_key_exists($f, $o)) {
        echo "{$f}=" . json_encode($o[$f]) . "\n";
    }
}

// Dump any shipment-related header fields
foreach ($o as $k => $v) {
    if (preg_match('/ship|deliver|complete|qty|open|status|date/i', (string) $k)) {
        if (! in_array($k, $headerFields, true) && ! is_array($v)) {
            echo "{$k}=" . json_encode($v) . "\n";
        }
    }
}

$lines = $o['Details'] ?? $o['DocumentDetails'] ?? [];
echo "\n=== LINES (" . count($lines) . ") ===\n";
foreach ($lines as $i => $l) {
    if (! is_array($l)) {
        continue;
    }
    echo "\n--- line {$i} ---\n";
    $fields = [
        'LineNbr', 'InventoryID', 'TransactionDescr', 'Description', 'UOM',
        'OrderQty', 'OpenQty', 'ShippedQty', 'QtyOnShipments', 'CancelledQty',
        'Completed', 'Closed', 'LineStatus', 'Status',
        'CuryUnitPrice', 'UnitPrice', 'CuryExtPrice', 'ExtendedPrice', 'ExtPrice',
        'WarehouseID', 'SiteID', 'ShipOn', 'RequestedOn', 'ReasonCode',
        'QtyOnHand', 'UnbilledQty', 'BilledQty', 'BaseOrderQty', 'BaseOpenQty',
        'BaseShippedQty',
    ];
    foreach ($fields as $f) {
        if (array_key_exists($f, $l)) {
            echo "  {$f}=" . json_encode($l[$f]) . "\n";
        }
    }

    $mapped = App\Services\Admin\SalesOrderLineFulfillmentDeriver::mapFromRaw($l);
    echo "  [mapped] inventory={$mapped['inventory_id']} order={$mapped['order_qty']} open={$mapped['open_qty']} shipped={$mapped['shipped_qty']} qty_on_shipments={$mapped['qty_on_shipments']} ({$mapped['qty_on_shipments_source']}) unit_price={$mapped['unit_price']} bo={$mapped['backorder_qty']} status={$mapped['fulfillment_status']}\n";
    echo "  [mapped] open×price=" . App\Services\Admin\SalesOrderLineFulfillmentDeriver::openLineValue($mapped['open_qty'] > 0 ? $mapped['open_qty'] : $mapped['backorder_qty'], $mapped['unit_price']) . "\n";
    echo "  all_keys=" . implode(',', array_keys($l)) . "\n";
}

// Try related shipment endpoint if available
echo "\n=== TRY SHIPMENT LOOKUP ===\n";
try {
    // Common Acumatica endpoints
    foreach (['Shipment', 'SalesOrderShipment'] as $entity) {
        try {
            $filter = "OrderNbr eq '{$nbr}' or OrderNbr eq '{$nbr}'";
            // Shipment might use different filter
            if ($entity === 'Shipment') {
                // Often details expand
                $res = $client->get($entity, [
                    '$filter' => "OrderNbr eq '{$nbr}'",
                    '$top' => 20,
                    '$expand' => 'Details',
                ]);
            } else {
                $res = $client->get($entity, [
                    '$filter' => "OrderNbr eq '{$nbr}'",
                    '$top' => 20,
                ]);
            }
            echo "{$entity}: " . json_encode($res) . "\n";
        } catch (Throwable $e) {
            echo "{$entity} error: " . $e->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "shipment lookup failed: " . $e->getMessage() . "\n";
}

// Fill rate snapshot if any
$fr = App\Models\AcumaticaFillRateSnapshot::query()
    ->where('order_nbr', $nbr)
    ->orWhere('acumatica_order_nbr', $nbr)
    ->get();
echo "\n=== LOCAL FILL RATE ROWS: {$fr->count()} ===\n";
foreach ($fr as $row) {
    echo json_encode($row->toArray()) . "\n";
}
