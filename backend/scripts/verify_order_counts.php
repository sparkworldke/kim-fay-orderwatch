<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$juneStart = '2026-06-01 00:00:00';
$juneEnd = '2026-06-30 23:59:59';
$yesterday = '2026-07-06';

echo "=== June 2026 status counts ===\n";
$rows = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart, $juneEnd])
    ->selectRaw('LOWER(TRIM(status)) as st, COUNT(*) as c')
    ->groupBy('st')
    ->orderByDesc('c')
    ->get();
foreach ($rows as $r) {
    echo "{$r->st} => {$r->c}\n";
}

echo "\n=== June: completed ===\n";
$completed = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart, $juneEnd])
    ->whereRaw("LOWER(TRIM(status)) = 'completed'")
    ->count();
echo "completed: {$completed}\n";

echo "\n=== June incomplete (NOT completed/back order) ===\n";
$inc = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart, $juneEnd])
    ->whereRaw("LOWER(TRIM(status)) NOT IN ('completed', 'back order')")
    ->selectRaw("
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pending_exact,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as shipping_exact,
        SUM(CASE WHEN LOWER(TRIM(status)) LIKE '%pending%approval%' THEN 1 ELSE 0 END) as pending_like,
        SUM(CASE WHEN LOWER(TRIM(status)) LIKE '%shipping%' THEN 1 ELSE 0 END) as shipping_like
    ")
    ->first();
print_r($inc);

echo "\n=== June incomplete (only pending approval + shipping) ===\n";
$inc2 = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart, $juneEnd])
    ->whereIn(DB::raw('LOWER(TRIM(status))'), ['pending approval', 'shipping'])
    ->count();
echo "pending+shipping only: {$inc2}\n";

echo "\n=== Yesterday {$yesterday} ===\n";
$y = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$yesterday.' 00:00:00', $yesterday.' 23:59:59'])
    ->selectRaw("
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pending_approval,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as in_shipping,
        SUM(CASE WHEN LOWER(TRIM(status)) = 'completed' THEN 1 ELSE 0 END) as completed
    ")
    ->first();
print_r($y);

echo "\n=== Revenue yesterday ===\n";
$rev = DB::table('acumatica_sales_orders as o')
    ->leftJoin('acumatica_customers as c', 'c.acumatica_id', '=', 'o.customer_acumatica_id')
    ->where('o.order_type', 'SO')
    ->whereBetween('o.order_date', [$yesterday.' 00:00:00', $yesterday.' 23:59:59'])
    ->selectRaw("
        SUM(o.order_total) as total,
        SUM(CASE WHEN UPPER(TRIM(c.customer_class)) LIKE 'KP%' THEN o.order_total ELSE 0 END) as kp,
        SUM(CASE WHEN UPPER(TRIM(c.customer_class)) LIKE 'CS%' THEN o.order_total ELSE 0 END) as cs
    ")
    ->first();
print_r($rev);

echo "\n=== June totals ===\n";
$total = DB::table('acumatica_sales_orders')->where('order_type','SO')->whereBetween('order_date',[$juneStart,$juneEnd])->count();
echo "all june SO: {$total}\n";

echo "\n=== June incomplete excluding rejected/canceled ===\n";
$inc3 = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart, $juneEnd])
    ->whereRaw("LOWER(TRIM(status)) NOT IN ('completed', 'canceled', 'cancelled', 'rejected')")
    ->selectRaw("COUNT(*) as total, SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pa, SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as sh")
    ->first();
print_r($inc3);

echo "\n=== Open pipeline snapshot (current status, June order_date) ===\n";
$open = DB::table('acumatica_sales_orders')
    ->where('order_type', 'SO')
    ->whereBetween('order_date', [$juneStart, $juneEnd])
    ->whereIn(DB::raw('LOWER(TRIM(status))'), ['pending approval', 'shipping', 'back order', 'on hold', 'open'])
    ->selectRaw("COUNT(*) as total, SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pa, SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as sh")
    ->first();
print_r($open);

echo "\n=== Distinct order dates around Jul 6 ===\n";
$dates = DB::table('acumatica_sales_orders')->where('order_type','SO')->whereBetween('order_date',['2026-07-04 00:00:00','2026-07-08 23:59:59'])->selectRaw('DATE(order_date) as d, COUNT(*) as c')->groupBy('d')->orderBy('d')->get();
foreach($dates as $d){ echo "{$d->d} => {$d->c}\n"; }