<?php

/**
 * Verifies the Trading / Manufactured product-segment split that the
 * "Products Not Delivered" page filter relies on.
 *
 * Mirrors ItemsNotDeliveredController::rows() but only computes the
 * product_segment via FillRateBusinessCategory, then reports how many
 * not-delivered lines / SKUs / value fall into each segment.
 *
 * Usage: php scripts/verify_segment_split.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Services\Operations\FillRateBusinessCategory;
use Illuminate\Support\Facades\DB;

$dateExpr = 'COALESCE(DATE(aso.order_date), acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(acumatica_backorder_lines.synced_at))';

// Use a very wide window so we capture all local rows.
$from = '2020-01-01';
$to = '2030-12-31';

$lines = AcumaticaBackorderLine::query()
    ->leftJoin('acumatica_inventory_items as item', 'item.inventory_id', '=', 'acumatica_backorder_lines.inventory_id')
    ->leftJoin('acumatica_sales_orders as aso', function ($join) {
        $join->on('acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr')
            ->where('aso.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER);
    })
    ->whereRaw($dateExpr . ' >= ?', [$from])
    ->whereRaw($dateExpr . ' <= ?', [$to])
    ->select([
        'acumatica_backorder_lines.inventory_id',
        'acumatica_backorder_lines.order_qty',
        'acumatica_backorder_lines.shipped_qty',
        'acumatica_backorder_lines.qty_on_shipments',
        'acumatica_backorder_lines.invoiced_qty',
        'acumatica_backorder_lines.unit_price',
        'item.product_type',
    ])
    ->get();

$classifier = app(FillRateBusinessCategory::class);

$totals = ['manufactured' => ['lines' => 0, 'value' => 0.0, 'skus' => []], 'trading' => ['lines' => 0, 'value' => 0.0, 'skus' => []]];

foreach ($lines as $line) {
    $delivered = max((float) $line->shipped_qty, (float) $line->qty_on_shipments, (float) ($line->invoiced_qty ?? 0));
    $notDelivered = max(0, (float) $line->order_qty - $delivered);
    if ($notDelivered <= 0) {
        continue;
    }
    $segment = $classifier->classify($line->inventory_id, $line->product_type);
    if (!isset($totals[$segment])) {
        continue;
    }
    $totals[$segment]['lines']++;
    $totals[$segment]['value'] += $notDelivered * (float) $line->unit_price;
    $totals[$segment]['skus'][$line->inventory_id] = true;
}

echo "Products Not Delivered — segment split (full local data)\n";
echo str_repeat('-', 64) . "\n";
printf("%-14s %8s %10s %12s\n", 'Segment', 'Lines', 'SKUs', 'Value (KES)');
echo str_repeat('-', 64) . "\n";
foreach ($totals as $segment => $bucket) {
    printf("%-14s %8d %10d %12s\n",
        ucfirst($segment),
        $bucket['lines'],
        count($bucket['skus']),
        number_format(round($bucket['value'], 2), 2),
    );
}
echo str_repeat('-', 64) . "\n";
$allLines = array_sum(array_column($totals, 'lines'));
$allValue = array_sum(array_column($totals, 'value'));
$allSkus = count(array_merge(array_keys($totals['manufactured']['skus']), array_keys($totals['trading']['skus'])));
printf("%-14s %8d %10d %12s\n", 'TOTAL', $allLines, $allSkus, number_format(round($allValue, 2), 2));
echo "\n";
echo "Classifier source: FillRateBusinessCategory (prefix-based fallback).\n";
