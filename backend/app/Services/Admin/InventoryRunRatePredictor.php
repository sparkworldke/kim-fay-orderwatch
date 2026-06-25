<?php

namespace App\Services\Admin;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSalesOrderLine;
use Carbon\Carbon;


class InventoryRunRatePredictor
{
    /**
     * Predict stock depletion using historical run-rate logs, with order-line
     * shipped quantities as a fallback when insufficient log history exists.
     *
     * @return array{
     *   daily_run_rate: ?float,
     *   days_until_stockout: ?int,
     *   prediction_status: string,
     *   qty_delta: ?float,
     *   method: string
     * }
     */
    public function predict(AcumaticaInventoryItem $item, float $currentQty, ?float $previousQty = null): array
    {
        $qtyDelta = $previousQty !== null ? round($previousQty - $currentQty, 4) : null;

        $fromLogs = $this->predictFromLogs($item, $currentQty);
        if ($fromLogs['daily_run_rate'] !== null && $fromLogs['daily_run_rate'] > 0) {
            return array_merge($fromLogs, [
                'qty_delta' => $qtyDelta,
                'method'    => 'historical_sync_logs',
            ]);
        }

        $fromOrders = $this->predictFromOrderLines($item->inventory_id, $currentQty);

        return array_merge($fromOrders, [
            'qty_delta' => $qtyDelta,
            'method'    => 'order_line_shipped_fallback',
        ]);
    }

    /** @return array{daily_run_rate: ?float, days_until_stockout: ?int, prediction_status: string} */
    private function predictFromLogs(AcumaticaInventoryItem $item, float $currentQty): array
    {
        $logs = AcumaticaInventoryRunRateLog::where('inventory_item_id', $item->id)
            ->where('logged_at', '>=', now()->subDays(30))
            ->orderBy('logged_at')
            ->get(['qty_on_hand', 'logged_at']);

        if ($logs->count() < 2) {
            return [
                'daily_run_rate'      => null,
                'days_until_stockout' => null,
                'prediction_status'   => 'insufficient_history',
            ];
        }

        $totalDepletion = 0.0;
        $depletionDays  = 0;

        for ($i = 1; $i < $logs->count(); $i++) {
            $prev = (float) $logs[$i - 1]->qty_on_hand;
            $cur  = (float) $logs[$i]->qty_on_hand;
            $delta = $prev - $cur;

            if ($delta > 0) {
                $days = max(1, Carbon::parse($logs[$i - 1]->logged_at)
                    ->diffInDays(Carbon::parse($logs[$i]->logged_at)));
                $totalDepletion += $delta;
                $depletionDays  += $days;
            }
        }

        if ($totalDepletion <= 0 || $depletionDays <= 0) {
            return [
                'daily_run_rate'      => null,
                'days_until_stockout' => null,
                'prediction_status'   => 'stable_or_replenished',
            ];
        }

        $dailyRate = round($totalDepletion / $depletionDays, 4);
        $daysLeft  = $dailyRate > 0 ? (int) ceil($currentQty / $dailyRate) : null;

        return [
            'daily_run_rate'      => $dailyRate,
            'days_until_stockout' => $daysLeft,
            'prediction_status'   => $this->stockoutStatus($daysLeft),
        ];
    }

    /** @return array{daily_run_rate: ?float, days_until_stockout: ?int, prediction_status: string} */
    private function predictFromOrderLines(string $inventoryId, float $currentQty): array
    {
        $from = now()->subDays(30)->startOfDay();

        $shipped = (float) AcumaticaSalesOrderLine::query()
            ->where('inventory_id', $inventoryId)
            ->where('shipped_qty', '>', 0)
            ->where('updated_at', '>=', $from)
            ->sum('shipped_qty');

        if ($shipped <= 0) {
            return [
                'daily_run_rate'      => null,
                'days_until_stockout' => null,
                'prediction_status'   => 'insufficient_history',
            ];
        }

        $dailyRate = round($shipped / 30, 4);
        $daysLeft  = $dailyRate > 0 ? (int) ceil($currentQty / $dailyRate) : null;

        return [
            'daily_run_rate'      => $dailyRate,
            'days_until_stockout' => $daysLeft,
            'prediction_status'   => $this->stockoutStatus($daysLeft),
        ];
    }

    private function stockoutStatus(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }

        if ($daysLeft <= 7) {
            return 'critical';
        }

        if ($daysLeft <= 14) {
            return 'at_risk';
        }

        return 'healthy';
    }
}