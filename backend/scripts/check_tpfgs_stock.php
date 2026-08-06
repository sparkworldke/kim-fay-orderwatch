<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "warehouses config: ".json_encode(config('inventory.warehouses')).PHP_EOL;

if (! Schema::hasTable('inventory_warehouse_balances')) {
    echo "table inventory_warehouse_balances missing\n";
    exit(1);
}

$tpfgs = DB::table('inventory_warehouse_balances')->where('warehouse_id', 'TPFGS');
echo 'TPFGS balance rows: '.$tpfgs->count().PHP_EOL;
echo 'TPFGS with qty_on_hand > 0: '.DB::table('inventory_warehouse_balances')->where('warehouse_id', 'TPFGS')->where('qty_on_hand', '>', 0)->count().PHP_EOL;
echo 'TPFGS last synced_at: '.DB::table('inventory_warehouse_balances')->where('warehouse_id', 'TPFGS')->max('synced_at').PHP_EOL;

$sample = DB::table('inventory_warehouse_balances')
    ->where('warehouse_id', 'TPFGS')
    ->orderByDesc('qty_on_hand')
    ->limit(15)
    ->get(['inventory_id', 'qty_on_hand', 'qty_available', 'synced_at']);
echo "TPFGS top by on_hand:\n";
foreach ($sample as $r) {
    echo "  {$r->inventory_id} oh={$r->qty_on_hand} av={$r->qty_available} synced={$r->synced_at}\n";
}

echo "\nAll warehouses:\n";
$rows = DB::table('inventory_warehouse_balances')
    ->selectRaw('warehouse_id, count(*) as n, sum(case when coalesce(qty_on_hand,0)>0 then 1 else 0 end) as with_oh, max(synced_at) as last_sync')
    ->groupBy('warehouse_id')
    ->orderBy('warehouse_id')
    ->get();
foreach ($rows as $r) {
    echo "  {$r->warehouse_id}: n={$r->n} with_oh={$r->with_oh} last={$r->last_sync}\n";
}

echo "\nCron inventory jobs:\n";
if (Schema::hasTable('cron_jobs')) {
    $crons = DB::table('cron_jobs')
        ->where('job_key', 'like', 'inventory-sync-%')
        ->orderBy('job_key')
        ->get(['job_key', 'is_enabled', 'status', 'frequency_label', 'last_run_at', 'next_run_at']);
    foreach ($crons as $c) {
        echo "  {$c->job_key} enabled={$c->is_enabled} status={$c->status} last={$c->last_run_at} freq={$c->frequency_label}\n";
    }
}

echo "\nRecent inventory sync logs:\n";
if (Schema::hasTable('acumatica_sync_logs')) {
    $logs = DB::table('acumatica_sync_logs')
        ->whereIn('sync_type', ['inventory', 'inventory_stocks'])
        ->orderByDesc('id')
        ->limit(12)
        ->get(['id', 'sync_type', 'status', 'record_count', 'success_count', 'filters', 'started_at', 'ended_at', 'error_message']);
    foreach ($logs as $l) {
        $filters = is_string($l->filters) ? $l->filters : json_encode($l->filters);
        echo "  #{$l->id} {$l->sync_type} {$l->status} records={$l->record_count}/{$l->success_count} {$l->started_at} filters={$filters}\n";
        if ($l->error_message) {
            echo "    err: ".substr($l->error_message, 0, 200)."\n";
        }
    }
}
