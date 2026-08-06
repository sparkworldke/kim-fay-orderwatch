<?php

namespace App\Services\Sales;

use App\Models\AcumaticaSalesOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesConsultantInactivityDigestService
{
    public function __construct(private readonly SalesPortfolioService $portfolio) {}

    public function build(User $user, ?Carbon $lastLogin = null): array
    {
        $from = ($lastLogin ?? $user->created_at ?? now()->startOfMonth())->copy();
        $customerIds = $this->portfolio->customerIdsFor($user, 'self');
        $orders = AcumaticaSalesOrder::query()
            ->where('order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->whereIn('customer_acumatica_id', $customerIds ?: ['__none__'])
            ->where('order_date', '>=', $from)
            ->get(['id', 'status', 'customer_acumatica_id']);

        $status = fn ($row) => strtolower(trim((string) $row->status));
        $orderIds = $orders->pluck('id');
        $lines = DB::table('acumatica_sales_order_lines')
            ->whereIn('sales_order_id', $orderIds->isEmpty() ? [-1] : $orderIds)
            ->get(['inventory_id', 'order_qty', 'shipped_qty', 'qty_on_shipments', 'cancelled_qty', 'unfilled_reason_code']);
        $ownership = Product::query()->whereIn('inventory_id', $lines->pluck('inventory_id')->filter()->unique())
            ->pluck('ownership', 'inventory_id');

        $reasons = []; $segments = ['manufactured' => 0.0, 'partner' => 0.0, 'unclassified' => 0.0];
        $undeliveredLines = 0; $undeliveredUnits = 0.0;
        foreach ($lines as $line) {
            $delivered = max((float) $line->shipped_qty, (float) $line->qty_on_shipments);
            $outstanding = max(0, (float) $line->order_qty - $delivered - (float) $line->cancelled_qty);
            if ($outstanding <= 0) continue;
            $undeliveredLines++; $undeliveredUnits += $outstanding;
            $reason = trim((string) $line->unfilled_reason_code) ?: 'Reason not recorded';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            $segment = $ownership[$line->inventory_id] ?? 'unclassified';
            if (! isset($segments[$segment])) $segment = 'unclassified';
            $segments[$segment] += $outstanding;
        }
        arsort($reasons);

        $summary = [
            'total' => $orders->count(),
            'completed' => $orders->filter(fn ($o) => str_contains($status($o), 'complete'))->count(),
            'rejected' => $orders->filter(fn ($o) => str_contains($status($o), 'reject') || str_contains($status($o), 'cancel'))->count(),
            'shipping' => $orders->filter(fn ($o) => str_contains($status($o), 'ship'))->count(),
        ];
        $recommendations = [];
        if ($summary['shipping'] > 0) $recommendations[] = "Follow up on {$summary['shipping']} order(s) currently in shipping.";
        if ($summary['rejected'] > 0) $recommendations[] = "Review {$summary['rejected']} rejected/cancelled order(s) with the affected customers.";
        if ($undeliveredLines > 0) $recommendations[] = "Resolve {$undeliveredLines} undelivered line(s), starting with the most frequent reason.";
        if ($summary['total'] === 0) $recommendations[] = 'Contact assigned customers who have not ordered since your last login.';
        if ($recommendations === []) $recommendations[] = 'Review your customer portfolio and confirm upcoming orders and delivery expectations.';

        return [
            'period_label' => $from->timezone(config('app.timezone'))->format('d M Y, H:i').' to now',
            'orders' => $summary,
            'customers' => ['portfolio' => count($customerIds), 'with_orders' => $orders->pluck('customer_acumatica_id')->unique()->count()],
            'undelivered' => ['lines' => $undeliveredLines, 'units' => round($undeliveredUnits, 2),
                'manufactured_units' => round($segments['manufactured'], 2), 'partner_units' => round($segments['partner'], 2),
                'unclassified_units' => round($segments['unclassified'], 2), 'reasons' => array_slice($reasons, 0, 8, true)],
            'recommendations' => $recommendations,
        ];
    }
}
