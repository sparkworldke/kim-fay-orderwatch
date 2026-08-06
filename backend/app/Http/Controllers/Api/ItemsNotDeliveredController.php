<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Models\InventoryWarehouseBalance;
use App\Services\Operations\FillRateBusinessCategory;
use App\Support\DataScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemsNotDeliveredController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $rows = $this->cachedRows($request, $from, $to);
        $groups = $this->grouped($rows);
        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'data' => $groups->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $groups->count(),
                'last_page' => max(1, (int) ceil($groups->count() / $perPage)),
            ],
            'summary' => $this->summary($rows),
            'filter_options' => [
                'reasons' => $rows->map(fn ($row) => [
                    'key' => $row['reason_key'], 'label' => $row['reason_label'],
                ])->unique('key')->sortBy('label')->values(),
                // This page is scoped to in-stock only (not delivered but stock available).
                'stock_statuses' => [
                    ['key' => 'in_stock', 'label' => 'In stock (not delivered)'],
                ],
                'order_statuses' => collect([
                    'Open', 'Pending Approval', 'Shipping', 'Completed',
                    'On Hold', 'Credit Hold', 'Rejected', 'Cancelled', 'Canceled',
                ])
                    ->merge($rows->pluck('order_status')->filter()->map(fn ($s) => trim((string) $s)))
                    ->filter()
                    ->unique(fn ($status) => strtolower($status))
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->map(fn ($status) => ['key' => $status, 'label' => $status])
                    ->values(),
                'product_segments' => [
                    ['key' => 'manufactured', 'label' => 'Manufactured'],
                    ['key' => 'trading', 'label' => 'Trading (Partners)'],
                ],
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'scope' => [
                'stock_status' => 'in_stock',
                'description' => 'Only products not fully delivered that currently have enough stock (non-RMS).',
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $rows = $this->cachedRows($request, $from, $to);
        $summary = $this->summary($rows);
        $filename = "products-not-delivered-{$from->format('Ymd')}-{$to->format('Ymd')}.xlsx";

        return response()->streamDownload(function () use ($rows, $summary, $from, $to): void {
            $spreadsheet = new Spreadsheet();
            $summarySheet = $spreadsheet->getActiveSheet();
            $summarySheet->setTitle('Summary');
            $summarySheet->fromArray([
                ['Product In Stock (Not Delivered)', ''],
                ['Scope', 'Not delivered AND currently in stock (non-RMS)'],
                ['Period', $from->toDateString().' to '.$to->toDateString()],
                ['Affected SKUs', $summary['affected_skus']],
                ['Affected Outlets', $summary['affected_outlets']],
                ['Affected SOs', $summary['affected_orders']],
                ['Not Delivered Units', $summary['not_delivered_units']],
                ['Not Delivered Amount', $summary['not_delivered_amount']],
                ['In-Stock Amount', $summary['in_stock_amount']],
                [],
                ['Reason', 'SO Lines'],
            ], null, 'A1');
            $reasonRow = 11;
            foreach ($summary['reason_totals'] as $reason) {
                $summarySheet->fromArray([[$reason['label'], $reason['count']]], null, "A{$reasonRow}");
                $reasonRow++;
            }
            $summarySheet->getStyle('A1:B1')->getFont()->setBold(true);
            $summarySheet->getColumnDimension('A')->setWidth(30);
            $summarySheet->getColumnDimension('B')->setWidth(22);

            $this->writeDetailSheet($spreadsheet, 'All Details', $rows);
            $this->writeDetailSheet(
                $spreadsheet,
                'In Stock Not Delivered',
                $rows->where('stock_status', 'in_stock')->values(),
            );
            $spreadsheet->setActiveSheetIndex(0);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function period(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'brands' => ['nullable', 'array'],
            'customer_ids' => ['nullable', 'array'],
            'product_segments' => ['nullable', 'array'],
            'reasons' => ['nullable', 'array'],
            'stock_statuses' => ['nullable', 'array'],
            'order_statuses' => ['nullable', 'array'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return [
            Carbon::parse($validated['date_from'] ?? now()->startOfMonth())->startOfDay(),
            Carbon::parse($validated['date_to'] ?? now())->endOfDay(),
        ];
    }

    private function rows(Request $request, Carbon $from, Carbon $to): Collection
    {
        // Source rows from acumatica_backorder_lines — the populated, curated table
        // in this environment. acumatica_sales_order_lines holds no rows here, which
        // previously left the "Products Not Delivered" page empty. The not-delivered
        // figure uses an ordered-minus-delivered basis: max(0, order_qty - delivered),
        // where delivered is the largest of shipped, qty_on_shipments and invoiced
        // quantities. This is intentionally distinct from the Backorders page, which
        // reports Acumatica's open_qty remainder and is left untouched.
        $dateExpr = 'COALESCE(DATE(aso.order_date), acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(acumatica_backorder_lines.synced_at))';

        $query = AcumaticaBackorderLine::query()
            ->leftJoin('acumatica_inventory_items as item', 'item.inventory_id', '=', 'acumatica_backorder_lines.inventory_id')
            ->leftJoin('acumatica_sales_orders as aso', function ($join) {
                $join->on('acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr')
                    ->where('aso.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER);
            })
            ->leftJoin('acumatica_customers as customer', 'customer.acumatica_id', '=', 'acumatica_backorder_lines.customer_acumatica_id')
            ->leftJoin('acumatica_customers as parent', 'parent.acumatica_id', '=', 'customer.parent_acumatica_id')
            ->whereRaw($dateExpr.' >= ?', [$from->toDateString()])
            ->whereRaw($dateExpr.' <= ?', [$to->toDateString()])
            ->select([
                'acumatica_backorder_lines.order_nbr',
                'aso.order_date',
                // Prefer live SO header status (Shipping / Completed / Open / …)
                DB::raw('COALESCE(NULLIF(TRIM(aso.status), \'\'), NULLIF(TRIM(acumatica_backorder_lines.order_status), \'\')) as order_status'),
                'acumatica_backorder_lines.customer_acumatica_id as customer_id',
                DB::raw('COALESCE(customer.name, acumatica_backorder_lines.customer_name, aso.customer_name) as customer_name'),
                'aso.workflow_reason_label',
                'aso.workflow_sub_reason_code',
                'aso.rejection_reason_code',
                'aso.rejection_reason',
                'aso.on_hold_reason',
                'acumatica_backorder_lines.reason_code as unfilled_reason_code',
                'customer.status as customer_status',
                'customer.parent_acumatica_id',
                'parent.name as parent_customer_name',
                'acumatica_backorder_lines.inventory_id',
                'acumatica_backorder_lines.order_qty',
                'acumatica_backorder_lines.shipped_qty',
                'acumatica_backorder_lines.qty_on_shipments',
                'acumatica_backorder_lines.invoiced_qty',
                'acumatica_backorder_lines.unit_price',
                'item.description as item_description',
                'item.brand',
                'item.product_type',
            ])
            ->addSelect(DB::raw($dateExpr.' as effective_date'));

        $scopedIds = DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($scopedIds !== null) $query->whereIn('acumatica_backorder_lines.customer_acumatica_id', $scopedIds);
        if ($brands = array_values(array_filter((array) $request->input('brands', [])))) {
            $query->whereIn('item.brand', $brands);
        }
        if ($customerIds = array_values(array_filter((array) $request->input('customer_ids', [])))) {
            $query->whereIn('acumatica_backorder_lines.customer_acumatica_id', $customerIds);
        }

        $lines = $query->orderByRaw($dateExpr)->get();
        $balances = InventoryWarehouseBalance::query()
            ->whereIn('inventory_id', $lines->pluck('inventory_id')->filter()->unique())
            ->orderBy('warehouse_id')->get()
            ->reject(fn ($stock) => $this->isRmsWarehouse($stock->warehouse_id))
            ->groupBy('inventory_id');

        $classifier = app(FillRateBusinessCategory::class);

        $rows = $lines->map(function ($line) use ($balances, $classifier) {
            $delivered = max(
                (float) $line->shipped_qty,
                (float) $line->qty_on_shipments,
                (float) ($line->invoiced_qty ?? 0),
            );
            $notDelivered = max(0, (float) $line->order_qty - $delivered);
            $stocks = $balances->get($line->inventory_id, collect());
            $availableRows = $stocks->whereNotNull('qty_available');
            $totalAvailable = $availableRows->isEmpty() ? null : (float) $availableRows->sum('qty_available');
            $onHandRows = $stocks->whereNotNull('qty_on_hand');
            $totalOnHand = $onHandRows->isEmpty() ? null : (float) $onHandRows->sum('qty_on_hand');
            $resolved = $totalAvailable ?? $totalOnHand ?? 0;
            $stockStatus = $resolved <= 0
                ? 'out_of_stock'
                : ($resolved >= $notDelivered ? 'in_stock' : 'partial_stock');
            [$reasonKey, $reasonLabel] = $this->reason($line, $stockStatus);
            $unitPrice = (float) $line->unit_price;
            $orderDate = $line->effective_date ? Carbon::parse($line->effective_date) : null;
            $productSegment = $classifier->classify($line->inventory_id, $line->product_type);

            return [
                'order_nbr' => $line->order_nbr,
                'order_date' => $orderDate ? $orderDate->toDateString() : null,
                'order_status' => $line->order_status,
                'customer_id' => $line->customer_id,
                'customer_name' => $line->customer_name,
                'parent_customer_id' => $line->parent_acumatica_id ?: $line->customer_id,
                'parent_customer_name' => $line->parent_customer_name ?: $line->customer_name,
                'inventory_id' => $line->inventory_id,
                'product_name' => $line->item_description ?: $line->inventory_id,
                'brand' => $line->brand,
                'product_segment' => $productSegment,
                'product_segment_label' => $classifier->label($productSegment),
                'ordered_qty' => (float) $line->order_qty,
                'shipped_qty' => $delivered,
                'not_delivered_qty' => $notDelivered,
                'unit_price' => $unitPrice,
                'not_delivered_amount' => round($notDelivered * $unitPrice, 2),
                'reason_key' => $reasonKey,
                'reason_label' => $reasonLabel,
                'stock_status' => $stockStatus,
                'stock_status_label' => match ($stockStatus) {
                    'in_stock' => 'In stock (not delivered)',
                    'partial_stock' => 'Partial stock (not delivered)',
                    default => 'Out of stock (not delivered)',
                },
                'stock_basis' => $totalAvailable !== null ? 'available' : 'on_hand_fallback',
                'total_available' => $totalAvailable,
                'total_on_hand' => $totalOnHand,
                'age_days' => $orderDate ? (int) $orderDate->startOfDay()->diffInDays(now()) : 0,
                'warehouse_stocks' => $stocks->map(fn ($stock) => [
                    'warehouse_id' => $stock->warehouse_id,
                    'warehouse_name' => config("inventory.warehouse_labels.{$stock->warehouse_id}", $stock->warehouse_id),
                    'qty_available' => $stock->qty_available !== null ? (float) $stock->qty_available : null,
                    'qty_on_hand' => $stock->qty_on_hand !== null ? (float) $stock->qty_on_hand : null,
                ])->values()->all(),
            ];
        })->filter(fn ($row) => $row['not_delivered_qty'] > 0);

        // Page scope: only not-delivered lines that are currently in stock
        // (available/on-hand covers the shortfall). Partial and OOS are excluded.
        $rows = $rows->where('stock_status', 'in_stock')->values();

        if ($segments = array_values(array_filter((array) $request->input('product_segments', [])))) {
            $rows = $rows->whereIn('product_segment', $segments);
        }
        if ($reasons = array_values(array_filter((array) $request->input('reasons', [])))) {
            $rows = $rows->whereIn('reason_key', $reasons);
        }
        // stock_statuses query param ignored — this endpoint is always in_stock only.
        if ($orderStatuses = array_values(array_filter((array) $request->input('order_statuses', [])))) {
            $wanted = array_map(
                static fn ($status) => strtolower(trim((string) $status)),
                $orderStatuses,
            );
            $rows = $rows->filter(function (array $row) use ($wanted) {
                $status = strtolower(trim((string) ($row['order_status'] ?? '')));

                return $status !== '' && in_array($status, $wanted, true);
            });
        }

        return $rows->values();
    }

    private function cachedRows(Request $request, Carbon $from, Carbon $to): Collection
    {
        $filters = $request->except(['page', 'per_page']);
        ksort($filters);
        $domain = app(\App\Services\Cache\DomainCache::class);
        $key = 'not-delivered:rows:'.$domain->generation(\App\Services\Cache\DomainCache::NOT_DELIVERED)
            .':user:'.$request->user()->id.':'.hash(
                'sha256',
                $from->toIso8601String().'|'.$to->toIso8601String().'|'.json_encode($filters),
            );

        $compute = fn () => $this->rows($request, $from, $to);
        try {
            return Cache::store((string) config('cache.dashboard_store', 'redis'))
                ->remember($key, now()->addMinutes(15), $compute);
        } catch (\Throwable) {
            return Cache::store((string) config('cache.dashboard_fallback_store', 'database'))
                ->remember($key, now()->addMinutes(15), $compute);
        }
    }

    private function grouped(Collection $rows): Collection
    {
        return $rows->groupBy('inventory_id')->map(function (Collection $skuRows, string $inventoryId) {
            $outlets = $skuRows->groupBy('customer_id')->map(function (Collection $outletRows, string $customerId) {
                $orders = $outletRows->groupBy('order_nbr')->map(function (Collection $orderRows, string $orderNbr) {
                    $first = $orderRows->first();
                    return [
                        'order_nbr' => $orderNbr,
                        'order_date' => $first['order_date'],
                        'order_status' => $first['order_status'],
                        'ordered_qty' => round((float) $orderRows->sum('ordered_qty'), 4),
                        'shipped_qty' => round((float) $orderRows->sum('shipped_qty'), 4),
                        'not_delivered_qty' => round((float) $orderRows->sum('not_delivered_qty'), 4),
                        'unit_price' => $orderRows->count() === 1 ? $first['unit_price'] : null,
                        'not_delivered_amount' => round((float) $orderRows->sum('not_delivered_amount'), 2),
                        'reason_key' => $first['reason_key'],
                        'reason_label' => $first['reason_label'],
                        'stock_status' => $first['stock_status'],
                        'stock_status_label' => $first['stock_status_label'],
                        'stock_basis' => $first['stock_basis'],
                        'total_available' => $first['total_available'],
                        'total_on_hand' => $first['total_on_hand'],
                        'age_days' => $first['age_days'],
                        'warehouse_stocks' => $first['warehouse_stocks'],
                    ];
                })->sortByDesc('not_delivered_amount')->values();
                $first = $outletRows->first();
                return [
                    'customer_id' => $customerId,
                    'customer_name' => $first['customer_name'],
                    'parent_customer_id' => $first['parent_customer_id'],
                    'parent_customer_name' => $first['parent_customer_name'],
                    'totals' => $this->totals($outletRows),
                    'orders' => $orders,
                ];
            })->sortByDesc('totals.not_delivered_amount')->values();
            $first = $skuRows->first();
            return [
                'inventory_id' => $inventoryId,
                'product_name' => $first['product_name'],
                'brand' => $first['brand'],
                'totals' => $this->totals($skuRows),
                'outlets' => $outlets,
            ];
        })->sort(function ($a, $b) {
            return [$b['totals']['not_delivered_amount'], $b['totals']['not_delivered_qty']]
                <=> [$a['totals']['not_delivered_amount'], $a['totals']['not_delivered_qty']];
        })->values();
    }

    private function totals(Collection $rows): array
    {
        return [
            'ordered_qty' => round((float) $rows->sum('ordered_qty'), 4),
            'shipped_qty' => round((float) $rows->sum('shipped_qty'), 4),
            'not_delivered_qty' => round((float) $rows->sum('not_delivered_qty'), 4),
            'not_delivered_amount' => round((float) $rows->sum('not_delivered_amount'), 2),
            'orders' => $rows->pluck('order_nbr')->unique()->count(),
        ];
    }

    private function summary(Collection $rows): array
    {
        return [
            'affected_skus' => $rows->pluck('inventory_id')->unique()->count(),
            'affected_outlets' => $rows->pluck('customer_id')->unique()->count(),
            'affected_orders' => $rows->pluck('order_nbr')->unique()->count(),
            'not_delivered_units' => round((float) $rows->sum('not_delivered_qty'), 4),
            'not_delivered_amount' => round((float) $rows->sum('not_delivered_amount'), 2),
            'in_stock_amount' => round((float) $rows->where('stock_status', 'in_stock')->sum('not_delivered_amount'), 2),
            'reason_totals' => $rows->groupBy('reason_key')->map(fn ($reasonRows) => [
                'key' => $reasonRows->first()['reason_key'],
                'label' => $reasonRows->first()['reason_label'],
                'count' => $reasonRows->count(),
            ])->sortByDesc('count')->values(),
        ];
    }

    private function reason(object $line, string $stockStatus): array
    {
        $status = strtolower(trim((string) $line->order_status));
        $customerStatus = strtolower(trim((string) $line->customer_status));
        if (in_array($status, ['cancelled', 'canceled'], true)) return ['cancelled', 'Order Cancelled'];
        if ($status === 'rejected') return ['rejected', 'Order Rejected'];
        if (in_array($status, ['on hold', 'hold', 'credit hold', 'pending approval'], true)
            || in_array($customerStatus, ['on hold', 'hold', 'credit hold'], true)) {
            return ['on_hold', 'Account / Order on Hold'];
        }
        $recorded = collect([
            $line->workflow_reason_label, $line->on_hold_reason, $line->rejection_reason,
            $line->workflow_sub_reason_code, $line->rejection_reason_code, $line->unfilled_reason_code,
        ])->map(fn ($value) => trim((string) $value))->first(fn ($value) => $value !== '');
        if ($recorded) {
            $key = 'reason:'.strtolower(preg_replace('/[^a-z0-9]+/i', '_', $recorded));
            return [$key, ucwords(str_replace('_', ' ', $recorded))];
        }
        if ($stockStatus === 'out_of_stock') return ['out_of_stock', 'Out of Stock'];
        return ['unclassified', 'Unclassified'];
    }

    private function isRmsWarehouse(string $warehouseId): bool
    {
        $name = (string) config("inventory.warehouse_labels.{$warehouseId}", $warehouseId);
        return str_contains(strtoupper($warehouseId), 'RMS')
            || str_contains(strtoupper($name), 'RMS');
    }

    private function writeDetailSheet(Spreadsheet $spreadsheet, string $title, Collection $rows): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $headers = [
            'Inventory ID', 'Product', 'Brand', 'Parent Customer', 'Outlet', 'Customer ID',
            'SO', 'Order Date', 'Order Status', 'Ordered', 'Shipped', 'Not Delivered',
            'Unit Price', 'Not Delivered Amount', 'Primary Reason', 'Stock Status',
            'Stock Basis', 'Total Available', 'Total On Hand', 'Warehouse',
            'Warehouse Available', 'Warehouse On Hand',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $excelRows = [];
        foreach ($rows as $row) {
            $stocks = $row['warehouse_stocks'] ?: [[
                'warehouse_name' => '', 'qty_available' => null, 'qty_on_hand' => null,
            ]];
            foreach ($stocks as $stock) {
                $excelRows[] = [
                    $row['inventory_id'], $row['product_name'], $row['brand'],
                    $row['parent_customer_name'], $row['customer_name'], $row['customer_id'],
                    $row['order_nbr'], $row['order_date'], $row['order_status'],
                    $row['ordered_qty'], $row['shipped_qty'], $row['not_delivered_qty'],
                    $row['unit_price'], $row['not_delivered_amount'], $row['reason_label'],
                    $row['stock_status_label'], $row['stock_basis'], $row['total_available'],
                    $row['total_on_hand'], $stock['warehouse_name'], $stock['qty_available'],
                    $stock['qty_on_hand'],
                ];
            }
        }
        if ($excelRows !== []) $sheet->fromArray($excelRows, null, 'A2');
        $sheet->getStyle('A1:V1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (range('A', 'V') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
    }
}
