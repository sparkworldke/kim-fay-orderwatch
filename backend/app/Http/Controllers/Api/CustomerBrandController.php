<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Services\Reporting\CustomerBrandReportService;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBrandController extends Controller
{
    public function index(Request $request, CustomerBrandReportService $service): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'business_category' => ['nullable', 'in:manufactured,trading'],
            'segment' => ['nullable', 'in:KP,CS'],
            // Partner Brands cascade: manufactured | trading, plus specific brand name
            'partner_brand' => ['nullable', 'in:manufactured,trading'],
            'brand' => ['nullable', 'string', 'max:120'],
            // detail=0 skips nested sales_orders (faster for brand roll-up view).
            'detail' => ['nullable', 'in:0,1'],
        ]);

        [$from, $to] = $this->dates($validated);

        $includeDetail = ($validated['detail'] ?? '1') !== '0';

        // Flat line-query report (optimized). Nested SO detail optional for speed.
        $rows = collect($service->report(
            $request->user(),
            $from,
            $to,
            null,
            $validated['business_category'] ?? null,
            $validated['segment'] ?? null,
            $validated['partner_brand'] ?? null,
            $validated['brand'] ?? null,
            includeSalesOrders: $includeDetail,
        ));

        $search = strtolower(trim((string) ($validated['search'] ?? '')));
        if ($search !== '') {
            $rows = $rows->filter(function (array $row) use ($search) {
                $customerHay = strtolower(($row['customer']['name'] ?? '').' '.($row['customer']['id'] ?? ''));
                if (str_contains($customerHay, $search)) {
                    return true;
                }
                foreach ($row['brands'] ?? [] as $brandRow) {
                    if (str_contains(strtolower((string) ($brandRow['brand'] ?? '')), $search)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $allRows = $rows->values()->all();
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $rows->count(),
            ],
            'summary' => $service->portfolioTotals($allRows),
            'brand_rollup' => $service->brandRollup($allRows),
            'date_from' => $from,
            'date_to' => $to,
            'filters' => [
                'partner_brand' => $validated['partner_brand'] ?? null,
                'brand' => $validated['brand'] ?? null,
                'business_category' => $validated['business_category'] ?? null,
                'segment' => $validated['segment'] ?? null,
            ],
        ]);
    }

    public function show(Request $request, string $customerId, CustomerBrandReportService $service): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'business_category' => ['nullable', 'in:manufactured,trading'],
            'segment' => ['nullable', 'in:KP,CS'],
            'partner_brand' => ['nullable', 'in:manufactured,trading'],
            'brand' => ['nullable', 'string', 'max:120'],
        ]);
        $customer = AcumaticaCustomer::query()->where('acumatica_id', $customerId)->first();
        if (! $customer || DataScope::denyUnlessCustomerAccessible($request->user(), $customerId, $customer->customer_class)) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }
        [$from, $to] = $this->dates($validated);
        $row = $service->report(
            $request->user(),
            $from,
            $to,
            $customerId,
            $validated['business_category'] ?? null,
            $validated['segment'] ?? null,
            $validated['partner_brand'] ?? null,
            $validated['brand'] ?? null,
        )[0] ?? null;
        if (! $row) {
            $row = [
                'customer' => [
                    'id' => $customerId,
                    'name' => $customer->name,
                    'customer_class' => $customer->customer_class,
                ],
                'totals' => null,
                'brands' => [],
                'undelivered_reasons' => [],
                'sales_orders' => [],
            ];
        }

        return response()->json($row + ['date_from' => $from, 'date_to' => $to]);
    }

    private function dates(array $validated): array
    {
        return [
            $validated['date_from'] ?? now('Africa/Nairobi')->startOfMonth()->toDateString(),
            $validated['date_to'] ?? now('Africa/Nairobi')->toDateString(),
        ];
    }
}
