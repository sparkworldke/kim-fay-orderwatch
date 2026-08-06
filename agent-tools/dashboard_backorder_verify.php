<?php

/**
 * Recompute dashboard backorder value cards for a date window against local DB.
 * Formulas match OperationsController::backordersValueSummary.
 */

require __DIR__ . '/../backend/vendor/autoload.php';

$app = require __DIR__ . '/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Services\Admin\FillRateCalculator;
use App\Services\Operations\FillRateBusinessCategory;
use Illuminate\Support\Facades\DB;

$from = $argv[1] ?? '2026-07-22';
$to = $argv[2] ?? '2026-07-25';

$dateExpr = "COALESCE(DATE(aso.order_date), acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(acumatica_backorder_lines.synced_at))";

echo "=== Date window {$from} .. {$to} ===\n";
echo "total backorder_lines: " . AcumaticaBackorderLine::count() . "\n";
echo "active: " . AcumaticaBackorderLine::where('shortfall_kind', 'active_backorder')->count() . "\n";
echo "completed_shortfall: " . AcumaticaBackorderLine::where('shortfall_kind', 'completed_shortfall')->count() . "\n";
echo "max synced_at: " . AcumaticaBackorderLine::max('synced_at') . "\n";

$biz = app(FillRateBusinessCategory::class);
$fr = app(FillRateCalculator::class);

function fetchRows(string $mode, string $from, string $to, string $dateExpr)
{
    $q = DB::table('acumatica_backorder_lines')
        ->leftJoin('acumatica_sales_orders as aso', 'acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr')
        ->leftJoin('acumatica_inventory_items as ai', 'acumatica_backorder_lines.inventory_id', '=', 'ai.inventory_id');

    if ($mode === 'timeline') {
        $q->whereRaw($dateExpr . ' >= ?', [$from])->whereRaw($dateExpr . ' <= ?', [$to]);
    } elseif ($mode === 'order_date') {
        $q->whereDate('aso.order_date', '>=', $from)->whereDate('aso.order_date', '<=', $to);
    } elseif ($mode === 'order_date_active') {
        $q->whereDate('aso.order_date', '>=', $from)
            ->whereDate('aso.order_date', '<=', $to)
            ->where('acumatica_backorder_lines.shortfall_kind', 'active_backorder');
    } elseif ($mode === 'order_date_back_order_status') {
        $q->whereDate('aso.order_date', '>=', $from)
            ->whereDate('aso.order_date', '<=', $to)
            ->whereRaw('LOWER(TRIM(aso.status)) = ?', ['back order']);
    } elseif ($mode === 'all_active') {
        $q->where('acumatica_backorder_lines.shortfall_kind', 'active_backorder');
    }

    return $q->select([
        'acumatica_backorder_lines.*',
        'ai.product_type',
        'aso.status as sales_order_status',
        'aso.order_date as so_order_date',
    ])->get();
}

function summarize($rows, string $label, FillRateBusinessCategory $biz, FillRateCalculator $fr): array
{
    $customerClasses = AcumaticaCustomer::query()
        ->whereIn('acumatica_id', $rows->pluck('customer_acumatica_id')->filter()->unique())
        ->pluck('customer_class', 'acumatica_id');

    $totals = ['order_value' => 0.0, 'invoiced_value' => 0.0, 'backorder_value' => 0.0];
    $byProduct = [
        'manufactured' => ['order_value' => 0.0, 'invoiced_value' => 0.0, 'backorder_value' => 0.0],
        'trading' => ['order_value' => 0.0, 'invoiced_value' => 0.0, 'backorder_value' => 0.0],
    ];
    $byCustomer = [
        'KP' => ['order_value' => 0.0, 'invoiced_value' => 0.0, 'backorder_value' => 0.0],
        'CS' => ['order_value' => 0.0, 'invoiced_value' => 0.0, 'backorder_value' => 0.0],
    ];

    $rarSum = 0.0;
    $orders = [];
    $skus = [];
    $activeLines = 0;
    $shortfallCounts = [];
    $soStatusCounts = [];
    $fulfillmentCounts = [];
    $zeroBo = 0;
    $activeRar = 0.0;

    foreach ($rows as $row) {
        $orderQty = (float) ($row->order_qty ?? 0);
        $shippedQty = (float) ($row->shipped_qty ?? 0);
        $cancelledQty = max(0, (float) ($row->cancelled_qty ?? 0));
        $committedQty = max(0, (float) ($row->qty_on_shipments ?? 0));
        $unitPrice = (float) ($row->unit_price ?? 0);
        $netOrderQty = max(0, $orderQty - $cancelledQty);
        $cappedShipped = min(max($shippedQty, 0), $netOrderQty);
        $openQty = max(0, $netOrderQty - $cappedShipped);
        $backorderQty = max(0, $openQty - $committedQty);

        $orderValue = $orderQty * $unitPrice;
        $invoicedValue = $cappedShipped * $unitPrice;
        $backorderValue = $backorderQty * max(0, $unitPrice);

        $productSegment = $biz->classify($row->inventory_id ?? null, $row->product_type ?? null);
        $customerSegment = $fr->segmentForCustomerClass($customerClasses->get($row->customer_acumatica_id));

        $totals['order_value'] += $orderValue;
        $totals['invoiced_value'] += $invoicedValue;
        $totals['backorder_value'] += $backorderValue;
        $byProduct[$productSegment]['order_value'] += $orderValue;
        $byProduct[$productSegment]['invoiced_value'] += $invoicedValue;
        $byProduct[$productSegment]['backorder_value'] += $backorderValue;
        $byCustomer[$customerSegment]['order_value'] += $orderValue;
        $byCustomer[$customerSegment]['invoiced_value'] += $invoicedValue;
        $byCustomer[$customerSegment]['backorder_value'] += $backorderValue;

        $rar = (float) ($row->revenue_at_risk ?? 0);
        $rarSum += $rar;
        $sk = $row->shortfall_kind ?? '?';
        $shortfallCounts[$sk] = ($shortfallCounts[$sk] ?? 0) + 1;
        $soStatusCounts[$row->sales_order_status ?? '?'] = ($soStatusCounts[$row->sales_order_status ?? '?'] ?? 0) + 1;
        $fulfillmentCounts[$row->fulfillment_status ?? '?'] = ($fulfillmentCounts[$row->fulfillment_status ?? '?'] ?? 0) + 1;

        if ($sk === 'active_backorder' || $sk === null || $sk === '') {
            $activeLines++;
            $orders[$row->order_nbr] = true;
            $skus[$row->inventory_id] = true;
            $activeRar += $rar;
        }
        if ($backorderQty <= 0) {
            $zeroBo++;
        }
    }

    $round = static fn (array $a): array => array_map(static fn ($v) => round($v, 2), $a);

    $out = [
        'label' => $label,
        'rows' => $rows->count(),
        'active_lines' => $activeLines,
        'open_orders' => count($orders),
        'skus' => count($skus),
        'totals' => $round($totals),
        'by_product' => array_map($round, $byProduct),
        'by_customer' => array_map($round, $byCustomer),
        'sum_revenue_at_risk' => round($rarSum, 2),
        'active_revenue_at_risk' => round($activeRar, 2),
        'shortfall_kind' => $shortfallCounts,
        'so_status' => $soStatusCounts,
        'fulfillment' => $fulfillmentCounts,
        'zero_bo_qty_rows' => $zeroBo,
        'identity_gap' => round($totals['order_value'] - $totals['invoiced_value'] - $totals['backorder_value'], 2),
    ];

    echo "\n--- {$label} ---\n";
    echo json_encode($out, JSON_PRETTY_PRINT) . "\n";

    return $out;
}

$modes = [
    'timeline' => 'timeline date expr (dashboard default)',
    'order_date' => 'strict SO order_date (all shortfall kinds)',
    'order_date_active' => 'SO order_date + active_backorder only',
    'order_date_back_order_status' => 'SO order_date + header Status=Back Order',
    'all_active' => 'ALL active_backorder rows (no date filter)',
];

$results = [];
foreach ($modes as $mode => $label) {
    $rows = fetchRows($mode, $from, $to, $dateExpr);
    $results[$mode] = summarize($rows, $label, $biz, $fr);
}

$target = [
    'backorder_value' => 13776253.71,
    'invoiced_value' => 16976781.52,
    'order_value' => 31131528.36,
    'manufactured_bo' => 5866073.68,
    'trading_bo' => 7910180.03,
    'kp_bo' => 876472.45,
    'cs_bo' => 12899781.26,
    'open_lines' => 3000,
    'skus' => 290,
    'open_orders' => 344,
    'current_outstanding' => 30772084.15,
];

echo "\n=== Match vs user dashboard ===\n";
foreach ($results as $mode => $r) {
    $dBo = round($r['totals']['backorder_value'] - $target['backorder_value'], 2);
    $dInv = round($r['totals']['invoiced_value'] - $target['invoiced_value'], 2);
    $dOrd = round($r['totals']['order_value'] - $target['order_value'], 2);
    $dLines = $r['active_lines'] - $target['open_lines'];
    $dOrders = $r['open_orders'] - $target['open_orders'];
    $dSkus = $r['skus'] - $target['skus'];
    $dOut = round($r['active_revenue_at_risk'] - $target['current_outstanding'], 2);
    $dMfg = round($r['by_product']['manufactured']['backorder_value'] - $target['manufactured_bo'], 2);
    $dTrd = round($r['by_product']['trading']['backorder_value'] - $target['trading_bo'], 2);
    echo sprintf(
        "%s\n  Δbo=%s Δinv=%s Δord=%s Δlines=%s Δorders=%s Δskus=%s Δoutstanding=%s Δmfg=%s Δtrd=%s\n",
        $mode,
        $dBo,
        $dInv,
        $dOrd,
        $dLines,
        $dOrders,
        $dSkus,
        $dOut,
        $dMfg,
        $dTrd
    );
}

// Also: open SO lines from sales_order_lines table for same dates with open_qty > 0
echo "\n=== From acumatica_sales_order_lines (open_qty > 0) order_date window ===\n";
$sol = DB::table('acumatica_sales_order_lines as l')
    ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
    ->whereDate('o.order_date', '>=', $from)
    ->whereDate('o.order_date', '<=', $to)
    ->where('l.open_qty', '>', 0)
    ->select([
        'o.acumatica_order_nbr',
        'o.status',
        'l.inventory_id',
        'l.order_qty',
        'l.shipped_qty',
        'l.open_qty',
        'l.unit_price',
        'l.qty_on_shipments',
    ])
    ->get();

$ov = $iv = $bv = 0.0;
$orders = [];
$skus = [];
$status = [];
foreach ($sol as $r) {
    $oq = (float) $r->order_qty;
    $sq = (float) $r->shipped_qty;
    $p = (float) $r->unit_price;
    $open = (float) $r->open_qty;
    $commit = max(0, (float) ($r->qty_on_shipments ?? 0));
    $boQty = max(0, $open - $commit); // if open already residual, commit may double-count — also report pure open
    $ov += $oq * $p;
    $iv += min(max($sq, 0), $oq) * $p;
    $bv += $open * max(0, $p); // pure open qty × price (matches Excel unbilled style at line level)
    $orders[$r->acumatica_order_nbr] = true;
    $skus[$r->inventory_id] = true;
    $status[$r->status] = ($status[$r->status] ?? 0) + 1;
}
echo 'open lines: ' . $sol->count() . "\n";
echo 'orders: ' . count($orders) . ' skus: ' . count($skus) . "\n";
echo 'order_value: ' . round($ov, 2) . ' invoiced: ' . round($iv, 2) . ' open_value: ' . round($bv, 2) . "\n";
echo 'status: ' . json_encode($status) . "\n";
echo 'Δbo vs dashboard: ' . round($bv - $target['backorder_value'], 2) . "\n";

// pure open without commit subtraction on backorder_lines stored open_qty * unit_price
echo "\n=== Stored open_qty × unit_price on active BO lines (order_date) ===\n";
$active = fetchRows('order_date_active', $from, $to, $dateExpr);
$sumOpenPrice = 0.0;
$sumRar = 0.0;
foreach ($active as $r) {
    $sumOpenPrice += max(0, (float) $r->open_qty) * max(0, (float) $r->unit_price);
    $sumRar += (float) $r->revenue_at_risk;
}
echo 'open_qty*price: ' . round($sumOpenPrice, 2) . "\n";
echo 'revenue_at_risk: ' . round($sumRar, 2) . "\n";
echo 'Δ open*price vs dashboard bo: ' . round($sumOpenPrice - $target['backorder_value'], 2) . "\n";
echo 'Δ rar vs outstanding: ' . round($sumRar - $target['current_outstanding'], 2) . "\n";
