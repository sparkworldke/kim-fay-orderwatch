<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\Email;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AcumaticaImportController extends Controller
{
    public function customers(Request $request): JsonResponse
    {
        $today    = now()->toDateString();
        $dateFrom = $request->input('date_from', $today);
        $dateTo   = $request->input('date_to',   $today);
        $status   = $request->input('status', 'all');

        $successfulCount = AcumaticaCustomer::whereDate('synced_at', '>=', $dateFrom)
            ->whereDate('synced_at', '<=', $dateTo)
            ->count();

        $failedCount = AcumaticaDeadLetter::where('resource_type', 'customer')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->count();

        $stats = [
            'total'      => $successfulCount + $failedCount,
            'successful' => $successfulCount,
            'failed'     => $failedCount,
        ];

        if ($status === 'failed') {
            $items = AcumaticaDeadLetter::where('resource_type', 'customer')
                ->whereDate('created_at', '>=', $dateFrom)
                ->whereDate('created_at', '<=', $dateTo)
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->input('per_page', 50), 200));

            return response()->json(['stats' => $stats, 'items' => $items, 'mode' => 'failed']);
        }

        $query = AcumaticaCustomer::query()
            ->whereDate('synced_at', '>=', $dateFrom)
            ->whereDate('synced_at', '<=', $dateTo)
            ->orderByDesc('synced_at');

        return response()->json([
            'stats' => $stats,
            'items' => $query->paginate(min((int) $request->input('per_page', 50), 200)),
            'mode'  => 'successful',
        ]);
    }

    public function emails(Request $request): JsonResponse
    {
        $today    = now()->toDateString();
        $dateFrom = $request->input('date_from', $today);
        $dateTo   = $request->input('date_to',   $today);
        $status   = $request->input('status', 'all'); // all | read | unread

        $totalCount  = Email::whereDate('received_at', '>=', $dateFrom)->whereDate('received_at', '<=', $dateTo)->count();
        $readCount   = Email::whereDate('received_at', '>=', $dateFrom)->whereDate('received_at', '<=', $dateTo)->where('is_read', true)->count();
        $unreadCount = $totalCount - $readCount;

        $stats = [
            'total'  => $totalCount,
            'read'   => $readCount,
            'unread' => $unreadCount,
        ];

        $query = Email::query()
            ->whereDate('received_at', '>=', $dateFrom)
            ->whereDate('received_at', '<=', $dateTo)
            ->orderByDesc('received_at');

        if ($status === 'read') {
            $query->where('is_read', true);
        } elseif ($status === 'unread') {
            $query->where('is_read', false);
        }

        return response()->json([
            'stats' => $stats,
            'items' => $query->paginate(min((int) $request->input('per_page', 50), 200)),
        ]);
    }

    public function workflow(Request $request): JsonResponse
    {
        $today    = now()->toDateString();
        $dateFrom = $request->input('date_from', $today);
        $dateTo   = $request->input('date_to',   $today);
        $q        = trim((string) $request->input('q', ''));
        $orderType = strtoupper(trim((string) $request->input('order_type', 'all')));

        $query = AcumaticaSalesOrder::query()
            ->ofOrderType($orderType === 'ALL' ? null : $orderType)
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id')
            ->select([
                'acumatica_sales_orders.id',
                'acumatica_sales_orders.acumatica_order_nbr',
                'acumatica_sales_orders.order_type',
                'acumatica_sales_orders.customer_acumatica_id',
                DB::raw('COALESCE(acumatica_sales_orders.customer_name, ac.name) as customer_name'),
                'acumatica_sales_orders.order_date',
                'acumatica_sales_orders.approved_at',
                'acumatica_sales_orders.shipped_at',
                'acumatica_sales_orders.completed_at',
                'acumatica_sales_orders.status',
            ])
            ->whereDate('acumatica_sales_orders.order_date', '>=', $dateFrom)
            ->whereDate('acumatica_sales_orders.order_date', '<=', $dateTo)
            ->orderByDesc('acumatica_sales_orders.order_date');

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('acumatica_sales_orders.acumatica_order_nbr', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_name', 'like', "%{$q}%")
                   ->orWhere('ac.name', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_acumatica_id', 'like', "%{$q}%");
            });
        }

        return response()->json($query->paginate(min((int) $request->input('per_page', 100), 200)));
    }

    public function truncateEmails(Request $request): JsonResponse
    {
        DB::table('emails')->delete();

        return response()->json(['message' => 'Email import data cleared successfully.']);
    }

    public function truncateOrders(Request $request): JsonResponse
    {
        DB::table('acumatica_sales_order_lines')->truncate();
        DB::table('acumatica_sales_orders')->truncate();

        return response()->json(['message' => 'Sales order data cleared successfully.']);
    }

    public function truncateCustomers(Request $request): JsonResponse
    {
        DB::table('acumatica_customers')->truncate();

        return response()->json(['message' => 'Customer data cleared successfully.']);
    }

    public function truncateBackorders(Request $request): JsonResponse
    {
        $scope = $this->validateClearScope($request);
        if ($scope['clear_all']) {
            $deleted = DB::table('acumatica_backorder_lines')->delete();
        } else {
            $orderNbrs = DB::table('acumatica_sales_orders')
                ->whereBetween('order_date', [$scope['date_from'].' 00:00:00', $scope['date_to'].' 23:59:59'])
                ->select('acumatica_order_nbr');
            $deleted = DB::table('acumatica_backorder_lines')->whereIn('order_nbr', $orderNbrs)->delete();
        }

        return response()->json($this->clearResponse('Backorder', $deleted, $scope));
    }

    public function truncateFillRate(Request $request): JsonResponse
    {
        $scope = $this->validateClearScope($request);
        if ($scope['clear_all']) {
            $deleted = DB::table('acumatica_fill_rate_snapshots')->delete();
        } else {
            $orders = DB::table('acumatica_sales_orders')
                ->whereBetween('order_date', [$scope['date_from'].' 00:00:00', $scope['date_to'].' 23:59:59']);
            $orderIds = (clone $orders)->select('id');
            $orderNbrs = (clone $orders)->select('acumatica_order_nbr');
            $deleted = DB::table('acumatica_fill_rate_snapshots')
                ->where(function ($query) use ($orderIds, $orderNbrs) {
                    $query->whereIn('sales_order_id', $orderIds)->orWhereIn('order_nbr', $orderNbrs);
                })
                ->delete();
        }

        return response()->json($this->clearResponse('Fill rate', $deleted, $scope));
    }

    /** @return array{clear_all:bool,date_from:?string,date_to:?string} */
    private function validateClearScope(Request $request): array
    {
        $validated = $request->validate([
            'clear_all' => ['nullable', 'boolean'],
            'date_from' => ['required_unless:clear_all,true', 'nullable', 'date', 'before_or_equal:date_to', 'prohibited_if:clear_all,true'],
            'date_to' => ['required_unless:clear_all,true', 'nullable', 'date', 'prohibited_if:clear_all,true'],
        ]);

        return [
            'clear_all' => (bool) ($validated['clear_all'] ?? false),
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
        ];
    }

    /** @param array{clear_all:bool,date_from:?string,date_to:?string} $scope */
    private function clearResponse(string $label, int $deleted, array $scope): array
    {
        return [
            'message' => $scope['clear_all']
                ? "{$label} data cleared successfully ({$deleted} rows)."
                : "{$label} data cleared for {$scope['date_from']} to {$scope['date_to']} ({$deleted} rows).",
            'deleted_count' => $deleted,
            'clear_all' => $scope['clear_all'],
            'date_from' => $scope['date_from'],
            'date_to' => $scope['date_to'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $today     = now()->toDateString();
        $dateFrom  = $request->input('date_from', $today);
        $dateTo    = $request->input('date_to',   $today);
        $status    = $request->input('status', 'all'); // all | successful | failed
        $orderType = strtoupper(trim((string) $request->input('order_type', 'all')));

        $stats = $this->orderImportStats($dateFrom, $dateTo);

        if ($status === 'failed') {
            $items = AcumaticaDeadLetter::where('resource_type', 'sales_order')
                ->whereDate('created_at', '>=', $dateFrom)
                ->whereDate('created_at', '<=', $dateTo)
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->input('per_page', 50), 200));

            return response()->json(['stats' => $stats, 'items' => $items, 'mode' => 'failed']);
        }

        $query = AcumaticaSalesOrder::query()
            ->ofOrderType($orderType === 'ALL' ? null : $orderType)
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id')
            ->select([
                'acumatica_sales_orders.*',
                DB::raw('COALESCE(acumatica_sales_orders.customer_name, ac.name) as customer_name'),
            ])
            ->whereDate('acumatica_sales_orders.order_date', '>=', $dateFrom)
            ->whereDate('acumatica_sales_orders.order_date', '<=', $dateTo)
            ->orderByDesc('acumatica_sales_orders.order_date');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('acumatica_sales_orders.acumatica_order_nbr', 'like', '%'.$search.'%')
                    ->orWhere('acumatica_sales_orders.customer_order', 'like', '%'.$search.'%')
                    ->orWhere('acumatica_sales_orders.customer_name', 'like', '%'.$search.'%')
                    ->orWhere('ac.name', 'like', '%'.$search.'%')
                    ->orWhere('acumatica_sales_orders.customer_acumatica_id', 'like', '%'.$search.'%');
            });
        }

        return response()->json([
            'stats' => $stats,
            'items' => $query->paginate(min((int) $request->input('per_page', 50), 200)),
            'mode'  => 'successful',
        ]);
    }

    /** @return array{total: int, successful: int, failed: int, so: int, qt: int, rc: int, other: int, in_scope_so: int} */
    private function orderImportStats(string $dateFrom, string $dateTo): array
    {
        $byType = AcumaticaSalesOrder::query()
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->select(['order_type', DB::raw('COUNT(*) as cnt')])
            ->groupBy('order_type')
            ->pluck('cnt', 'order_type');

        $so = (int) ($byType[AcumaticaSalesOrder::TYPE_SALES_ORDER] ?? 0);
        $qt = (int) ($byType[AcumaticaSalesOrder::TYPE_QUOTE] ?? 0)
            + (int) ($byType['QO'] ?? 0);
        $rc = (int) ($byType[AcumaticaSalesOrder::TYPE_CREDIT_NOTE] ?? 0);
        $other = (int) $byType->except([
            AcumaticaSalesOrder::TYPE_SALES_ORDER,
            AcumaticaSalesOrder::TYPE_QUOTE,
            'QO',
            AcumaticaSalesOrder::TYPE_CREDIT_NOTE,
        ])->sum();

        $successful = $so + $qt + $rc + $other;

        $failed = (int) AcumaticaDeadLetter::where('resource_type', 'sales_order')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->count();

        return [
            'total'       => $successful + $failed,
            'successful'  => $successful,
            'failed'      => $failed,
            'so'          => $so,
            'qt'          => $qt,
            'rc'          => $rc,
            'other'       => $other,
            'in_scope_so' => $so,
        ];
    }
}
