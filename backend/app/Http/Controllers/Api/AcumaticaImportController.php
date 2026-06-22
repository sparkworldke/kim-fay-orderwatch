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

        $query = AcumaticaSalesOrder::query()
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

    public function index(Request $request): JsonResponse
    {
        $today    = now()->toDateString();
        $dateFrom = $request->input('date_from', $today);
        $dateTo   = $request->input('date_to',   $today);
        $status   = $request->input('status', 'all'); // all | successful | failed

        // Stats and list filtered by Acumatica order_date
        $successfulCount = AcumaticaSalesOrder::whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->count();

        $failedCount = AcumaticaDeadLetter::where('resource_type', 'sales_order')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->count();

        $stats = [
            'total'      => $successfulCount + $failedCount,
            'successful' => $successfulCount,
            'failed'     => $failedCount,
        ];

        if ($status === 'failed') {
            $items = AcumaticaDeadLetter::where('resource_type', 'sales_order')
                ->whereDate('created_at', '>=', $dateFrom)
                ->whereDate('created_at', '<=', $dateTo)
                ->orderByDesc('created_at')
                ->paginate(min((int) $request->input('per_page', 50), 200));

            return response()->json(['stats' => $stats, 'items' => $items, 'mode' => 'failed']);
        }

        $query = AcumaticaSalesOrder::query()
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id')
            ->select([
                'acumatica_sales_orders.*',
                DB::raw('COALESCE(acumatica_sales_orders.customer_name, ac.name) as customer_name'),
            ])
            ->whereDate('acumatica_sales_orders.order_date', '>=', $dateFrom)
            ->whereDate('acumatica_sales_orders.order_date', '<=', $dateTo)
            ->orderByDesc('acumatica_sales_orders.order_date');

        return response()->json([
            'stats' => $stats,
            'items' => $query->paginate(min((int) $request->input('per_page', 50), 200)),
            'mode'  => 'successful',
        ]);
    }
}
