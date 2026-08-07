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
            'channel' => ['required', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'region' => ['nullable', 'string', 'max:100'],
            'comparison' => ['nullable', 'string', 'in:previous_month,past_3_months,past_6_months'],
        ]);

        // The channel filter accepts a single channel or a comma-separated list
        // (e.g. "KP", "MT1,MT2", or "MT,GT,KP"). Unknown tokens are dropped so
        // the view can offer a multi-channel / "any channel" selector.
        $allowedChannels = ['PORTFOLIO', 'MT', 'MT1', 'MT2', 'GT', 'DTC_DTB', 'ECOMMERCE', 'KP'];
        $channels = collect(explode(',', (string) $validated['channel']))
            ->map(fn ($channel) => strtoupper(trim($channel)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $channels = array_values(array_intersect($allowedChannels, $channels));
        abort_if($channels === [], 422, 'At least one valid Sales Intelligence channel is required.');

        return response()->json($service->metrics(
            $request->user(),
            $channels,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['region'] ?? null,
            $validated['comparison'] ?? null,
        ));
    }
}
