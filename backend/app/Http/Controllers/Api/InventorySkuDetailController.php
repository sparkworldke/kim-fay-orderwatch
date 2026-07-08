<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventorySkuDetailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventorySkuDetailController extends Controller
{
    public function __construct(
        private readonly InventorySkuDetailService $service,
    ) {}

    /**
     * Return SKU detail with historical sales, AI predictions, and prediction period.
     *
     * GET /api/operations/inventory/{inventoryId}/sku-detail
     *
     * Query params:
     *   - date_from  (required) YYYY-MM-DD
     *   - date_to    (required) YYYY-MM-DD
     *
     * Responses:
     *   200  SkuDetailResponse JSON
     *   404  Inventory item not found
     *   422  Invalid date range (< 7 or > 730 days)
     */
    public function show(Request $request, string $inventoryId): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to'   => ['required', 'date_format:Y-m-d'],
        ]);

        $detail = $this->service->getDetail(
            $inventoryId,
            $request->input('date_from'),
            $request->input('date_to'),
        );

        return response()->json($detail);
    }
}
