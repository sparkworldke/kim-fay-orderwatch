<?php

/**
 * Live Acumatica vs local OrderWatch backorder value comparison.
 *
 * Usage:
 *   php scripts/compare_live_bo_value.php [date_from] [date_to]
 * Defaults: 2026-07-27 2026-07-27
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AcumaticaBackorderLine;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use Illuminate\Support\Facades\DB;

$from = $argv[1] ?? '2026-07-27';
$to = $argv[2] ?? '2026-07-27';
$dashboard = 15_440_314.76;

echo "=== Compare live Acumatica vs local vs dashboard ===\n";
echo "Window: {$from} .. {$to}\n";
echo 'Dashboard (user): KES '.number_format($dashboard, 2)."\n\n";

// --- Local DB ---
$dateExpr = 'COALESCE(DATE(aso.order_date), b.requested_on, b.scheduled_shipment_date, DATE(b.synced_at))';

$localModes = [
    'local_timeline_active' => function () use ($from, $to, $dateExpr) {
        return DB::table('acumatica_backorder_lines as b')
            ->leftJoin('acumatica_sales_orders as aso', 'b.order_nbr', '=', 'aso.acumatica_order_nbr')
            ->where(function ($q) {
                $q->where('b.shortfall_kind', 'active_backorder')->orWhereNull('b.shortfall_kind');
            })
            ->whereRaw("{$dateExpr} >= ?", [$from])
            ->whereRaw("{$dateExpr} <= ?", [$to])
            ->get(['b.open_qty', 'b.unit_price', 'b.revenue_at_risk', 'b.order_qty', 'b.shipped_qty', 'b.qty_on_shipments', 'b.cancelled_qty', 'b.inventory_id', 'b.order_nbr']);
    },
    'local_all_active_no_date' => function () {
        return DB::table('acumatica_backorder_lines as b')
            ->where(function ($q) {
                $q->where('b.shortfall_kind', 'active_backorder')->orWhereNull('b.shortfall_kind');
            })
            ->get(['b.open_qty', 'b.unit_price', 'b.revenue_at_risk', 'b.order_qty', 'b.shipped_qty', 'b.qty_on_shipments', 'b.cancelled_qty', 'b.inventory_id', 'b.order_nbr']);
    },
];

foreach ($localModes as $label => $fn) {
    $rows = $fn();
    $openTimesPrice = 0.0;
    $rar = 0.0;
    $residual = 0.0;
    $orders = [];
    $skus = [];
    $lines = 0;
    foreach ($rows as $r) {
        $oq = (float) ($r->open_qty ?? 0);
        $price = (float) ($r->unit_price ?? 0);
        $openTimesPrice += max(0, $oq) * max(0, $price);
        $rar += (float) ($r->revenue_at_risk ?? 0);
        $res = SalesOrderLineFulfillmentDeriver::residualOpenQty(
            (float) ($r->order_qty ?? 0),
            (float) ($r->shipped_qty ?? 0),
            (float) ($r->qty_on_shipments ?? 0),
            (float) ($r->cancelled_qty ?? 0),
            $oq > 0 ? $oq : null,
        );
        $residual += SalesOrderLineFulfillmentDeriver::openLineValue($res, $price);
        if ($oq > 0 || $res > 0) {
            $lines++;
        }
        if ($r->order_nbr) {
            $orders[$r->order_nbr] = true;
        }
        if ($r->inventory_id) {
            $skus[$r->inventory_id] = true;
        }
    }
    echo "--- {$label} ---\n";
    echo '  rows='.count($rows)." open_lines~={$lines} orders=".count($orders).' skus='.count($skus)."\n";
    echo '  open_qty*price     = '.number_format($openTimesPrice, 2)."\n";
    echo '  residual open value= '.number_format($residual, 2)."\n";
    echo '  sum revenue_at_risk= '.number_format($rar, 2)."\n";
    echo '  Δ residual vs dash = '.number_format($residual - $dashboard, 2)."\n\n";
}

// --- Live Acumatica ---
/** @var AcumaticaClient $client */
$client = app(AcumaticaClient::class);

$scenarios = [
    'live_open_for_backorders_date_window' => fn () => $client->fetchAllOpenSalesOrdersForBackordersByDateRange($from, $to),
    'live_open_for_backorders_all' => fn () => $client->fetchAllOpenSalesOrdersForBackorders(),
    'live_all_so_date_window' => fn () => $client->fetchAllSalesOrdersByDateRange($from, $to),
];

foreach ($scenarios as $label => $fn) {
    echo "--- {$label} (fetching live...) ---\n";
    try {
        $started = microtime(true);
        $orders = $fn();
        $secs = round(microtime(true) - $started, 1);
        echo '  fetched orders='.count($orders)." in {$secs}s\n";

        $openValue = 0.0;
        $openLines = 0;
        $ordersWithOpen = 0;
        $skus = [];
        $statusOpen = [];

        foreach ($orders as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $status = (string) (AcumaticaClient::val($raw['Status'] ?? null) ?? '');
            $details = $raw['Details'] ?? [];
            if (! is_array($details)) {
                $details = [];
            }
            $orderOpen = 0.0;
            $any = false;
            foreach ($details as $lineRaw) {
                if (! is_array($lineRaw)) {
                    continue;
                }
                $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);
                $orderQty = (float) ($mapped['order_qty'] ?? 0);
                $shipped = (float) ($mapped['shipped_qty'] ?? 0);
                $cancelled = (float) ($mapped['cancelled_qty'] ?? 0);
                $openQty = SalesOrderLineFulfillmentDeriver::resolveOpenQty($lineRaw, $orderQty, $shipped, $cancelled);
                $unitPrice = (float) ($mapped['unit_price'] ?? 0);
                if ($openQty <= 0) {
                    continue;
                }
                if (! SalesOrderLineFulfillmentDeriver::isBackorderLine(
                    $orderQty,
                    $shipped,
                    (float) ($mapped['qty_on_shipments'] ?? 0),
                    $cancelled,
                    $openQty,
                )) {
                    // Still count pure open value for unbilled comparison
                }
                $val = SalesOrderLineFulfillmentDeriver::openLineValue($openQty, $unitPrice);
                $orderOpen += $val;
                $openValue += $val;
                $openLines++;
                $any = true;
                $inv = AcumaticaClient::val($lineRaw['InventoryID'] ?? null);
                if ($inv) {
                    $skus[(string) $inv] = true;
                }
            }
            if ($any) {
                $ordersWithOpen++;
                $statusOpen[$status] = ($statusOpen[$status] ?? 0) + $orderOpen;
            }
        }

        echo "  orders_with_open={$ordersWithOpen} open_lines={$openLines} skus=".count($skus)."\n";
        echo '  LIVE open value (OpenQty×UnitPrice / residual) = '.number_format($openValue, 2)."\n";
        echo '  Δ live vs dashboard = '.number_format($openValue - $dashboard, 2)."\n";
        arsort($statusOpen);
        foreach ($statusOpen as $st => $v) {
            echo '    status '.($st !== '' ? $st : '?').': '.number_format($v, 2)."\n";
        }
        echo "\n";
    } catch (Throwable $e) {
        echo '  ERROR: '.$e->getMessage()."\n\n";
    }
}

echo "Done.\n";
