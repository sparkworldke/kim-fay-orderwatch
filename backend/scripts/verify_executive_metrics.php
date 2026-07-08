<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Reports\DailyExecutiveReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$asOf = Carbon::parse('2026-07-07 01:40:00', 'Africa/Nairobi');
$reportDateStr = $asOf->copy()->subDay()->toDateString();

echo "=== Report date: {$reportDateStr} ===\n\n";

// Yesterday orders (exact status match)
$row = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$reportDateStr.' 00:00:00', $reportDateStr.' 23:59:59'])
    ->selectRaw("
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as shipping,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'completed' THEN 1 ELSE 0 END) as completed
    ")
    ->first();
echo "Yesterday orders (exact status): ".json_encode($row)."\n";

// June prior month carryover (all incomplete SO)
$juneStart = '2026-06-01';
$juneEnd = '2026-06-30';
$capturedSql = "LOWER(TRIM(status)) IN ('completed', 'back order')";

$juneCarryover = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart.' 00:00:00', $juneEnd.' 23:59:59'])
    ->whereRaw("NOT ({$capturedSql})")
    ->selectRaw("
        COUNT(*) as total_incomplete,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as shipping
    ")
    ->first();
echo "June incomplete carryover: ".json_encode($juneCarryover)."\n";

// Zones count
echo "Shipping zones: ".DB::table('acumatica_shipping_zones')->count()."\n";
echo "Nairobi zones: ".DB::table('acumatica_shipping_zones')->where('region', 'nairobi')->count()."\n";

$payload = app(DailyExecutiveReportService::class)->buildPayload($asOf, 'Africa/Nairobi');
echo "\n=== Payload ===\n";
echo "Yesterday: ".json_encode($payload['orders']['yesterday'])."\n";
echo "Prior month: ".json_encode($payload['orders']['prior_month_carryover'])."\n";
echo "SLA Nairobi: ".json_encode($payload['sla']['nairobi'])."\n";
echo "Fill rate: ".json_encode($payload['fill_rate'] ?? [])."\n";
echo "Backorders: ".json_encode($payload['backorders'] ?? [])."\n";

echo "\n=== Expected (production 6 Jul 2026) ===\n";
echo "Yesterday: 198 total, 35 pending, 123 shipping\n";
echo "June carryover: 371 incomplete, 16 pending, 111 shipping\n";