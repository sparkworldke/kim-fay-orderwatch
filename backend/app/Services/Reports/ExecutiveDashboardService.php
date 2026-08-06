<?php

namespace App\Services\Reports;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExecutiveDashboardService
{
    private const SEGMENTS = ['MT1', 'MT2', 'GT', 'ECOMMERCE', 'DTC_DTB', 'KP'];

    public function metrics(User $viewer, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        abort_if($start->gt($end), 422, 'From must be before To.');

        $metrics = Cache::remember("executive:pulse:{$start->toDateString()}:{$end->toDateString()}", now()->addMinutes(5), function () use ($start, $end) {
            $current = $this->sales($start, $end);
            $days = $start->diffInDays($end) + 1;
            $priorEnd = $start->copy()->subDay()->endOfDay();
            $priorStart = $priorEnd->copy()->subDays($days - 1)->startOfDay();
            $prior = $this->sales($priorStart, $priorEnd);

            return [
                'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'prior_from' => $priorStart->toDateString(), 'prior_to' => $priorEnd->toDateString()],
                'totals' => $this->withChange($current['totals'], $prior['totals']),
                'segments' => collect(self::SEGMENTS)->map(fn ($code) => [
                    'code' => $code,
                    ...$this->withChange($current['segments'][$code], $prior['segments'][$code]),
                ])->values()->all(),
                'trend' => $current['trend'],
                'gaps' => $this->gaps($start, $end),
            ];
        });

        return [...$metrics, 'focus' => $this->focusFor($viewer)];
    }

    private function sales(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('acumatica_sales_orders as o')
            ->leftJoin('acumatica_customers as c', 'c.acumatica_id', '=', 'o.customer_acumatica_id')
            ->where('o.order_type', 'SO')
            ->whereBetween('o.order_date', [$from, $to])
            ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(o.status, "")))'), ['cancelled', 'canceled', 'rejected'])
            ->get(['o.id', 'o.order_date', 'o.order_total', 'c.sales_channel_code', 'c.customer_class']);

        $segments = array_fill_keys(self::SEGMENTS, ['revenue' => 0.0, 'orders' => 0]);
        $trend = [];
        foreach ($rows as $row) {
            $segment = $this->segment($row->sales_channel_code, $row->customer_class);
            $date = Carbon::parse($row->order_date)->toDateString();
            $value = (float) $row->order_total;
            $trend[$date] ??= ['date' => $date, 'revenue' => 0.0, 'orders' => 0, 'segments' => []];
            $trend[$date]['revenue'] += $value;
            $trend[$date]['orders']++;
            if ($segment) {
                $segments[$segment]['revenue'] += $value;
                $segments[$segment]['orders']++;
                $trend[$date]['segments'][$segment] ??= ['revenue' => 0.0, 'orders' => 0];
                $trend[$date]['segments'][$segment]['revenue'] += $value;
                $trend[$date]['segments'][$segment]['orders']++;
            }
        }
        ksort($trend);

        return ['totals' => ['revenue' => round((float) $rows->sum('order_total'), 2), 'orders' => $rows->count()], 'segments' => $segments, 'trend' => array_values($trend)];
    }

    private function segment(?string $channel, ?string $class): ?string
    {
        $channel = strtoupper(trim((string) $channel));
        if (in_array($channel, self::SEGMENTS, true)) return $channel;
        if ($channel === 'ECOMMERCE') return 'ECOMMERCE';
        if (str_starts_with(strtoupper(trim((string) $class)), 'KP')) return 'KP';
        return null;
    }

    private function gaps(Carbon $from, Carbon $to): array
    {
        $backorders = Schema::hasTable('acumatica_backorder_lines') ? DB::table('acumatica_backorder_lines') : null;
        $fill = Schema::hasTable('acumatica_fill_rate_snapshots') ? DB::table('acumatica_fill_rate_snapshots as f')->join('acumatica_sales_orders as o', 'o.id', '=', 'f.sales_order_id')->whereBetween('o.order_date', [$from, $to]) : null;
        return [
            'backorder_lines' => $backorders ? (clone $backorders)->count() : 0,
            'revenue_at_risk' => $backorders ? round((float) (clone $backorders)->sum('revenue_at_risk'), 2) : 0,
            'fill_rate_pct' => $fill ? round((float) ((clone $fill)->whereNotNull('fill_rate_pct')->avg('fill_rate_pct') ?? 0), 1) : null,
            'critical_skus' => Schema::hasTable('acumatica_inventory_run_rate_logs') ? DB::table('acumatica_inventory_run_rate_logs')->where('prediction_status', 'critical')->distinct()->count('inventory_id') : 0,
            'not_delivered_qty' => Schema::hasTable('acumatica_sales_order_lines') ? round((float) DB::table('acumatica_sales_order_lines')->sum('open_qty'), 2) : 0,
        ];
    }

    private function withChange(array $current, array $prior): array
    {
        return [...$current, 'revenue_change_pct' => $prior['revenue'] ? round((($current['revenue'] - $prior['revenue']) / $prior['revenue']) * 100, 1) : null, 'orders_change_pct' => $prior['orders'] ? round((($current['orders'] - $prior['orders']) / $prior['orders']) * 100, 1) : null];
    }

    private function focusFor(User $user): ?string
    {
        return match ($user->primaryDepartment()?->slug) { 'kp' => 'KP', 'gt' => 'GT', 'mt_consumer_sales' => 'MT', 'partner_brands' => 'PARTNER', 'production', 'procurement', 'fleet' => 'GAPS', default => null };
    }
}
