<?php

/**
 * Quick post-sync checks for warehouse TPFGS.
 *
 *   php artisan tinker
 *   or: php scripts/verify_tpfgs_after_sync.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$wh = 'TPFGS';

$total = DB::table('inventory_warehouse_balances')->where('warehouse_id', $wh)->count();
$withOh = DB::table('inventory_warehouse_balances')->where('warehouse_id', $wh)->where('qty_on_hand', '>', 0)->count();
$withAv = DB::table('inventory_warehouse_balances')->where('warehouse_id', $wh)->where('qty_available', '>', 0)->count();
$sumOh = DB::table('inventory_warehouse_balances')->where('warehouse_id', $wh)->sum('qty_on_hand');
$last = DB::table('inventory_warehouse_balances')->where('warehouse_id', $wh)->max('synced_at');

echo "TPFGS balances: {$total}\n";
echo "with qty_on_hand > 0: {$withOh}\n";
echo "with qty_available > 0: {$withAv}\n";
echo "sum qty_on_hand: {$sumOh}\n";
echo "last synced_at: {$last}\n\n";

echo "Top 15 by qty_on_hand:\n";
$rows = DB::table('inventory_warehouse_balances')
    ->where('warehouse_id', $wh)
    ->orderByDesc('qty_on_hand')
    ->limit(15)
    ->get(['inventory_id', 'qty_on_hand', 'qty_available', 'synced_at']);

foreach ($rows as $r) {
    echo sprintf(
        "  %-16s oh=%s av=%s synced=%s\n",
        $r->inventory_id,
        $r->qty_on_hand,
        $r->qty_available ?? 'null',
        $r->synced_at,
    );
}

$log = DB::table('acumatica_sync_logs')
    ->whereIn('sync_type', ['inventory', 'inventory_stocks'])
    ->where(function ($q) use ($wh) {
        $q->where('filters', 'like', '%'.$wh.'%')
            ->orWhere('filters', 'like', '%tpfgs%');
    })
    ->orderByDesc('id')
    ->first();

if ($log) {
    echo "\nLatest TPFGS-ish sync log #{$log->id}: status={$log->status} records={$log->record_count} success={$log->success_count} started={$log->started_at}\n";
    echo "filters: {$log->filters}\n";
}
