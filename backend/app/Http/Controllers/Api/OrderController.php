<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaSalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AcumaticaSalesOrder::query()
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id')
            ->withCount('lines')
            ->select([
                'acumatica_sales_orders.id',
                'acumatica_sales_orders.acumatica_order_nbr',
                'acumatica_sales_orders.order_type',
                'acumatica_sales_orders.customer_acumatica_id',
                // Resolve name: use order's own field first, fall back to customers table
                DB::raw('COALESCE(acumatica_sales_orders.customer_name, ac.name) as customer_name'),
                'acumatica_sales_orders.customer_order',
                'acumatica_sales_orders.status',
                'acumatica_sales_orders.match_status',
                'acumatica_sales_orders.flag_source',
                'acumatica_sales_orders.order_date',
                'acumatica_sales_orders.ship_date',
                'acumatica_sales_orders.requested_on',
                'acumatica_sales_orders.last_modified_at',
                'acumatica_sales_orders.approved_at',
                'acumatica_sales_orders.shipped_at',
                'acumatica_sales_orders.completed_at',
                'acumatica_sales_orders.order_total',
                'acumatica_sales_orders.currency_id',
                'acumatica_sales_orders.rejection_reason',
                'acumatica_sales_orders.on_hold_reason',
                'acumatica_sales_orders.email_subject',
                'acumatica_sales_orders.email_received_at',
                'acumatica_sales_orders.synced_at',
            ])
            ->orderByDesc('acumatica_sales_orders.order_date');

        if ($request->has('date_from')) {
            $query->whereDate('acumatica_sales_orders.order_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('acumatica_sales_orders.order_date', '<=', $request->input('date_to'));
        }

        if ($request->has('customer_id')) {
            $query->where('acumatica_sales_orders.customer_acumatica_id', $request->input('customer_id'));
        }

        if ($request->has('status')) {
            $query->where('acumatica_sales_orders.status', $request->input('status'));
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('acumatica_sales_orders.acumatica_order_nbr', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_name', 'like', "%{$q}%")
                   ->orWhere('ac.name', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_order', 'like', "%{$q}%");
            });
        }

        return response()->json($query->paginate(min((int) $request->input('per_page', 50), 200)));
    }

    public function show(string $id): JsonResponse
    {
        $order = AcumaticaSalesOrder::with(['lines', 'customer'])
            ->where('acumatica_order_nbr', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        // Resolve name inline for the detail view too
        if (! $order->customer_name && $order->customer) {
            $order->customer_name = $order->customer->name;
        }

        return response()->json($order);
    }

    /**
     * Return status-breakdown counts matching the same filters as index().
     * Used by the stat cards on the Orders page and Dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        $base = AcumaticaSalesOrder::query()
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id');

        if ($request->has('date_from')) {
            $base->whereDate('acumatica_sales_orders.order_date', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $base->whereDate('acumatica_sales_orders.order_date', '<=', $request->input('date_to'));
        }
        if ($request->has('customer_id')) {
            $base->where('acumatica_sales_orders.customer_acumatica_id', $request->input('customer_id'));
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $base->where(function ($qb) use ($q) {
                $qb->where('acumatica_sales_orders.acumatica_order_nbr', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_name', 'like', "%{$q}%")
                   ->orWhere('ac.name', 'like', "%{$q}%");
            });
        }

        $rows = (clone $base)
            ->select(['acumatica_sales_orders.status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as cnt')])
            ->groupBy('acumatica_sales_orders.status')
            ->pluck('cnt', 'status')
            ->mapWithKeys(fn ($cnt, $status) => [strtolower(trim($status ?? 'unknown')) => (int) $cnt])
            ->toArray();

        return response()->json([
            'total'            => (int) (clone $base)->count(),
            'completed'        => $rows['completed']        ?? 0,
            'shipping'         => $rows['shipping']         ?? 0,
            'pending_approval' => $rows['pending approval'] ?? 0,
            'rejected'         => $rows['rejected']         ?? 0,
            'on_hold'          => ($rows['on hold'] ?? 0) + ($rows['credit hold'] ?? 0),
            'open'             => $rows['open']             ?? 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Orders are managed via Acumatica sync.'], 405);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $order = AcumaticaSalesOrder::where('acumatica_order_nbr', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'match_status'     => ['sometimes', 'string', 'in:pending,matched,unmatched,duplicate,escalated,missing'],
            'flag_source'      => ['sometimes', 'nullable', 'string', 'in:acumatica,email'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'on_hold_reason'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email_subject'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'email_received_at'=> ['sometimes', 'nullable', 'date'],
        ]);

        $order->update($validated);

        return response()->json($order->withCount('lines')->first());
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Orders are managed via Acumatica sync.'], 405);
    }
}
