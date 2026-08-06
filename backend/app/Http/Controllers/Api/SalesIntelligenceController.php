<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesIntelligenceController extends Controller
{
    public function metrics(Request $request, SalesIntelligenceService $service): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', 'in:PORTFOLIO,MT,MT1,MT2,GT,DTC_DTB,ECOMMERCE,KP'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'region' => ['nullable', 'string', 'max:100'],
            'comparison' => ['nullable', 'string', 'in:previous_month,past_3_months,past_6_months'],
        ]);

        return response()->json($service->metrics(
            $request->user(),
            $validated['channel'],
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['region'] ?? null,
            $validated['comparison'] ?? null,
        ));
    }
}
