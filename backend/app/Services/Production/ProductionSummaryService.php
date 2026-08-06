<?php

namespace App\Services\Production;

use App\Models\DailyStockSummary;
use App\Models\InventoryWarehouseBalance;
use App\Models\MonthlySkuSummary;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductionSummaryService
{
    public const VERSION_STOCK = 'production:version:stock';
    public const VERSION_SALES = 'production:version:sales';
    public const VERSION_REFERENCE = 'production:version:reference';

    public function refreshMonthly(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        // A monthly bucket is replaced atomically; always rebuild complete months
        // even when the caller only knows that a recent day changed.
        $from = Carbon::parse($from)->startOfMonth();
        $to = Carbon::parse($to)->endOfMonth();
        $driver = DB::connection()->getDriverName();
        $monthSql = $driver === 'sqlite'
            ? "strftime('%Y-%m-01', so.order_date)"
            : "DATE_FORMAT(so.order_date, '%Y-%m-01')";
        $delivered = $driver === 'sqlite'
            ? 'MAX(COALESCE(sol.shipped_qty, 0), COALESCE(sol.qty_on_shipments, 0))'
            : 'GREATEST(COALESCE(sol.shipped_qty, 0), COALESCE(sol.qty_on_shipments, 0))';
        $missed = "CASE WHEN COALESCE(sol.order_qty, 0) > {$delivered} THEN COALESCE(sol.order_qty, 0) - {$delivered} ELSE 0 END";
        $refreshedAt = now();
        $version = hash('sha256', $refreshedAt->toIso8601String()."|{$from}|{$to}");

        $rows = DB::table('acumatica_sales_order_lines as sol')
            ->join('acumatica_sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->where('so.order_type', 'SO')
            ->whereBetween('so.order_date', [$from, $to])
            ->whereNotNull('sol.inventory_id')
            ->selectRaw("sol.inventory_id, COALESCE(sol.warehouse_id, '') as warehouse_id, {$monthSql} as month")
            ->selectRaw('SUM(COALESCE(sol.order_qty, 0)) as ordered_qty')
            ->selectRaw("SUM({$delivered}) as delivered_qty")
            ->selectRaw("SUM({$missed}) as missed_qty")
            ->selectRaw("SUM(CASE WHEN COALESCE(sol.unit_price, 0) > 0 THEN ({$missed}) * sol.unit_price ELSE 0 END) as missed_revenue")
            ->selectRaw("SUM(CASE WHEN COALESCE(sol.unit_price, 0) > 0 THEN {$missed} ELSE 0 END) as priced_missed_qty")
            ->selectRaw("CASE WHEN SUM({$missed}) <= SUM(CASE WHEN COALESCE(sol.unit_price, 0) > 0 THEN {$missed} ELSE 0 END) THEN 1 ELSE 0 END as revenue_complete")
            ->selectRaw("MIN(NULLIF(so.currency_id, '')) as currency_id")
            ->groupBy('sol.inventory_id', 'sol.warehouse_id', DB::raw($monthSql))
            ->get();

        DB::transaction(function () use ($rows, $from, $to, $version, $refreshedAt): void {
            MonthlySkuSummary::query()
                ->whereBetween('month', [$from->copy()->startOfMonth(), $to->copy()->startOfMonth()])
                ->delete();
            foreach ($rows->chunk(1000) as $chunk) {
                MonthlySkuSummary::query()->insert($chunk->map(fn ($row) => [
                    'inventory_id' => $row->inventory_id,
                    'warehouse_id' => $row->warehouse_id ?? '',
                    'month' => $row->month,
                    'ordered_qty' => $row->ordered_qty,
                    'delivered_qty' => $row->delivered_qty,
                    'missed_qty' => $row->missed_qty,
                    'missed_revenue' => (float) $row->missed_qty > 0 && (float) $row->priced_missed_qty <= 0
                        ? null : $row->missed_revenue,
                    'priced_missed_qty' => $row->priced_missed_qty,
                    'revenue_complete' => (bool) $row->revenue_complete,
                    'currency_id' => $row->currency_id,
                    'source_version' => $version,
                    'source_refreshed_at' => $refreshedAt,
                    'created_at' => $refreshedAt,
                    'updated_at' => $refreshedAt,
                ])->all());
            }
        });

        $this->bumpVersion(self::VERSION_SALES);
        return $rows->count();
    }

    public function refreshDailyStock(CarbonInterface|string|null $date = null): int
    {
        $date = Carbon::parse($date ?? now())->startOfDay();
        $refreshedAt = now();
        $version = hash('sha256', $refreshedAt->toIso8601String());
        $rows = InventoryWarehouseBalance::query()
            ->leftJoin('acumatica_inventory_items as item', 'item.inventory_id', '=', 'inventory_warehouse_balances.inventory_id')
            ->leftJoin('production_sku_plans as plan', 'plan.inventory_item_id', '=', 'item.id')
            ->select([
                'inventory_warehouse_balances.inventory_id',
                'inventory_warehouse_balances.warehouse_id',
                'inventory_warehouse_balances.qty_on_hand',
                'inventory_warehouse_balances.qty_available',
                'plan.msi',
            ])
            ->selectRaw('CASE WHEN inventory_warehouse_balances.qty_on_hand IS NOT NULL AND inventory_warehouse_balances.qty_available IS NOT NULL THEN inventory_warehouse_balances.qty_on_hand - inventory_warehouse_balances.qty_available ELSE NULL END as qty_allocated')
            ->get();

        DB::transaction(function () use ($rows, $date, $version, $refreshedAt): void {
            DailyStockSummary::query()->whereDate('summary_date', $date)->delete();
            foreach ($rows->chunk(1000) as $chunk) {
                DailyStockSummary::query()->insert($chunk->map(function ($row) use ($date, $version, $refreshedAt) {
                    $stock = $row->qty_available ?? $row->qty_on_hand;
                    $msi = $row->msi;
                    $status = $msi !== null && (float) $msi > 0
                        ? ((float) $stock / (float) $msi < .5 ? 'critical' : ((float) $stock / (float) $msi < 1 ? 'at-risk' : 'healthy'))
                        : null;
                    return [
                        'inventory_id' => $row->inventory_id,
                        'warehouse_id' => $row->warehouse_id,
                        'summary_date' => $date->toDateString(),
                        'qty_on_hand' => $row->qty_on_hand,
                        'qty_available' => $row->qty_available,
                        'qty_allocated' => $row->qty_allocated,
                        'msi' => $msi,
                        'months_of_cover' => null,
                        'msi_status' => $status,
                        'source_version' => $version,
                        'source_refreshed_at' => $refreshedAt,
                        'created_at' => $refreshedAt,
                        'updated_at' => $refreshedAt,
                    ];
                })->all());
            }
        });
        $this->bumpVersion(self::VERSION_STOCK);
        return $rows->count();
    }

    public function versions(): array
    {
        return [
            'stock' => Cache::get(self::VERSION_STOCK)
                ?? DailyStockSummary::max('source_version'),
            'sales' => Cache::get(self::VERSION_SALES)
                ?? MonthlySkuSummary::max('source_version'),
            'reference' => Cache::get(self::VERSION_REFERENCE)
                ?? DB::table('products')->max('updated_at'),
            'stock_refreshed_at' => DailyStockSummary::max('source_refreshed_at'),
            'sales_refreshed_at' => MonthlySkuSummary::max('source_refreshed_at'),
        ];
    }

    public function bumpVersion(string $key): string
    {
        $version = hash('sha256', $key.'|'.now()->format('Y-m-d H:i:s.u'));
        Cache::forever($key, $version);
        Cache::forever('production:cache-generation', (int) Cache::get('production:cache-generation', 0) + 1);
        return $version;
    }
}
