<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Return KPI summary data for the dashboard.
     */
    public function kpis(): JsonResponse
    {
        // TODO: replace with real queries when models/data exist
        return response()->json([
            'total_orders'        => 0,
            'pending_orders'      => 0,
            'discrepancies'       => 0,
            'customers'           => 0,
            'revenue_today'       => 0,
            'orders_today'        => 0,
        ]);
    }
}
