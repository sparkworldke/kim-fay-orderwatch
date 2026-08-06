<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;

/**
 * Canonical in-memory metrics for transformed backorder rows.
 *
 * List, dashboard and export callers must pass rows through BackorderLineTransformer
 * first so quantity/value and stock diagnosis use one definition.
 */
class BackorderMetricsService
{
    /** @return array<string, mixed> */
    public function summarize(Collection $lines): array
    {
        $active = $lines->filter(
            fn ($line) => ($line->shortfall_kind ?? 'active_backorder') === 'active_backorder'
        );

        $segmentZero = fn (): array => [
            'revenue_at_risk' => 0.0,
            'line_count' => 0,
            'order_count' => 0,
        ];
        $bySegment = [
            FillRateBusinessCategory::MANUFACTURED => $segmentZero(),
            FillRateBusinessCategory::TRADING => $segmentZero(),
        ];

        foreach ($bySegment as $segment => $_) {
            $rows = $active->where('product_segment', $segment);
            $bySegment[$segment] = [
                'revenue_at_risk' => round((float) $rows->sum('revenue_at_risk'), 2),
                'line_count' => $rows->count(),
                'order_count' => $rows->pluck('order_nbr')->filter()->unique()->count(),
            ];
        }

        $byBrand = $active
            ->groupBy(fn ($line) => ($line->brand ?: 'Unclassified').'|'.$line->product_segment)
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'brand' => $first->brand ?: 'Unclassified',
                    'product_segment' => $first->product_segment,
                    'revenue_at_risk' => round((float) $rows->sum('revenue_at_risk'), 2),
                    'line_count' => $rows->count(),
                    'order_count' => $rows->pluck('order_nbr')->filter()->unique()->count(),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values()
            ->all();

        $diagnosis = [
            'rar_true_stockout' => 0.0,
            'rar_partial_cover' => 0.0,
            'rar_stock_available_not_shipped' => 0.0,
            'fgs_synced_at' => $active->pluck('fgs_synced_at')->filter()->max(),
        ];
        foreach ($active as $line) {
            $key = match ($line->stock_signal ?? null) {
                'true_stockout' => 'rar_true_stockout',
                'partial_cover' => 'rar_partial_cover',
                'stock_available_not_shipped' => 'rar_stock_available_not_shipped',
                default => null,
            };
            if ($key !== null) {
                $diagnosis[$key] += (float) $line->revenue_at_risk;
            }
        }
        foreach (array_keys($diagnosis) as $key) {
            if ($key !== 'fgs_synced_at') $diagnosis[$key] = round($diagnosis[$key], 2);
        }

        $reasoned = $active->filter(fn ($line) => filled($line->reason_code ?? null))->count();
        $backfilled = $active->where('first_backordered_at_is_backfilled', true)->count();
        $excluded = $active->where('is_excluded_from_kpi', true)->count();
        $count = $active->count();

        return [
            'open_episodes' => $count,
            'open_orders' => $active->pluck('order_nbr')->filter()->unique()->count(),
            'open_skus' => $active->pluck('inventory_id')->filter()->unique()->count(),
            'revenue_at_risk' => round((float) $active->sum('revenue_at_risk'), 2),
            'backorder_qty' => round((float) $active->sum('backorder_qty'), 4),
            'by_product_segment' => $bySegment,
            'by_brand' => $byBrand,
            'stock_diagnosis' => $diagnosis,
            'data_quality' => [
                'reason_coverage_pct' => $count > 0 ? round($reasoned / $count * 100, 1) : 0.0,
                'backfilled_pct' => $count > 0 ? round($backfilled / $count * 100, 1) : 0.0,
                'excluded_count' => $excluded,
            ],
        ];
    }
}
