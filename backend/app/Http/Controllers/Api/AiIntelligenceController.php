<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiIntelligenceBriefing;
use App\Services\AI\AiIntelligenceDataService;
use App\Services\AI\AiIntelligenceInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiIntelligenceController extends Controller
{
    public function __construct(
        private readonly AiIntelligenceDataService $data,
        private readonly AiIntelligenceInsightService $insights,
    ) {}

    /** Metrics only — returns cached AI insights when available, never calls the AI provider. */
    public function briefing(Request $request): JsonResponse
    {
        $validated = $this->validatedRange($request);
        $payload = $this->data->build($validated['date_from'], $validated['date_to']);
        $cached = $this->findCached($validated['date_from'], $validated['date_to']);

        return response()->json($this->buildResponse($payload, $cached));
    }

    /** On-demand AI generation — saves result for the date range unless regenerate is requested. */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from'  => ['required', 'date'],
            'date_to'    => ['required', 'date', 'after_or_equal:date_from'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        $payload = $this->data->build($validated['date_from'], $validated['date_to']);
        $cached = $this->findCached($validated['date_from'], $validated['date_to']);

        if ($cached && ! ($validated['regenerate'] ?? false)) {
            return response()->json($this->buildResponse($payload, $cached));
        }

        $ai = $this->insights->generate($payload);
        $insightBody = $this->extractInsightBody($ai);

        $cached = AiIntelligenceBriefing::updateOrCreate(
            [
                'date_from' => $validated['date_from'],
                'date_to'   => $validated['date_to'],
            ],
            [
                'insights'     => $insightBody,
                'ai_status'    => $ai['ai_status'] ?? 'unknown',
                'provider'     => $ai['provider'] ?? null,
                'generated_at' => now(),
            ],
        );

        return response()->json($this->buildResponse($payload, $cached));
    }

    /** @return array{date_from: string, date_to: string} */
    private function validatedRange(Request $request): array
    {
        return $request->validate([
            'date_from' => ['required', 'date'],
            'date_to'   => ['required', 'date', 'after_or_equal:date_from'],
        ]);
    }

    private function findCached(string $dateFrom, string $dateTo): ?AiIntelligenceBriefing
    {
        return AiIntelligenceBriefing::query()
            ->whereDate('date_from', $dateFrom)
            ->whereDate('date_to', $dateTo)
            ->first();
    }

    /** @param  array<string, mixed>  $payload */
    private function buildResponse(array $payload, ?AiIntelligenceBriefing $cached): array
    {
        $insights = $cached?->insightPayload();

        return [
            'period' => $payload['period'],
            'comparison_period' => $payload['comparison_period'],
            'metrics' => [
                'orders' => $payload['orders'],
                'orders_comparison' => $payload['orders_comparison'],
                'customers' => $payload['customers'],
                'daily_trend' => $payload['daily_trend'],
                'historical_weekly' => $payload['historical_weekly'],
                'projections' => $payload['projections'],
            ],
            'insights' => $insights,
            'insights_cached' => $cached !== null,
            'insights_generated_at' => $cached?->generated_at?->toIso8601String(),
            'ai_status' => $cached?->ai_status,
            'provider' => $cached?->provider,
            'generated_at' => $payload['generated_at'],
        ];
    }

    /** @param  array<string, mixed>  $ai */
    private function extractInsightBody(array $ai): array
    {
        return [
            'executive_summary'  => $ai['executive_summary'] ?? '',
            'orders'             => $ai['orders'] ?? ['summary' => '', 'highlights' => []],
            'customer_behaviour' => $ai['customer_behaviour'] ?? ['summary' => '', 'highlights' => []],
            'predictions'        => $ai['predictions'] ?? ['summary' => '', 'highlights' => []],
            'actions'            => $ai['actions'] ?? [],
        ];
    }
}