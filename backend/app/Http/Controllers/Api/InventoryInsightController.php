<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryInsightController extends Controller
{
    public function __construct(
        private readonly InventoryInsightService $service,
    ) {}

    /**
     * Return AI-powered variance insights for a single SKU.
     *
     * GET /api/operations/inventory/{inventoryId}/insights
     *
     * Query params:
     *   - date_from  (required) YYYY-MM-DD
     *   - date_to    (required) YYYY-MM-DD
     *
     * Responses:
     *   200  SkuInsightResponse JSON
     *   404  Inventory item not found (when the service reports unavailable w/ inventory_item gap)
     *   422  Invalid date range (< 7 or > 730 days)
     */
    public function show(Request $request, string $inventoryId): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to'   => ['required', 'date_format:Y-m-d'],
        ]);

        $from = \Carbon\Carbon::parse($request->input('date_from'))->startOfDay();
        $to   = \Carbon\Carbon::parse($request->input('date_to'))->startOfDay();
        $days = (int) $from->diffInDays($to);

        if ($days < 7 || $days > 730) {
            return response()->json([
                'message' => 'Date range must be between 7 and 730 days',
            ], 422);
        }

        // Check for request abort before starting the (potentially long) LLM call.
        if ($request->isMethod('GET') && connection_aborted()) {
            abort(499, 'Client closed connection');
        }

        $insights = $this->service->getInsights(
            $inventoryId,
            $request->input('date_from'),
            $request->input('date_to'),
        );

        return response()->json($insights);
    }
}
