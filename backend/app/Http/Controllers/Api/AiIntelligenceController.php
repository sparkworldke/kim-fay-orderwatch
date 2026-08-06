<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiGenerationException;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAiIntelligenceJob;
use App\Models\AiIntelligenceBriefing;
use App\Services\AI\AiIntelligenceDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiIntelligenceController extends Controller
{
    public function __construct(
        private readonly AiIntelligenceDataService $data,
    ) {}

    /** Metrics only — returns cached AI insights when available, never calls the AI provider. */
    public function briefing(Request $request): JsonResponse
    {
        $validated = $this->validatedRange($request);
        $payload = $this->data->build($validated['date_from'], $validated['date_to']);
        $cached = $this->findCached($validated['date_from'], $validated['date_to']);

        return response()->json($this->buildResponse($payload, $cached));
    }

    /**
     * Enqueue AI generation (async). With QUEUE_CONNECTION=sync the job finishes before return.
     * Returns 202 when still queued/running; 200 when ready; 4xx/5xx on lock/config errors.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        $payload = $this->data->build($validated['date_from'], $validated['date_to']);
        $cached = $this->findCached($validated['date_from'], $validated['date_to']);
        $regenerate = (bool) ($validated['regenerate'] ?? false);

        if ($cached && $cached->ai_status === 'success' && ! $regenerate) {
            return response()->json($this->buildResponse($payload, $cached));
        }

        if ($cached && in_array($cached->ai_status, ['queued', 'running'], true) && ! $regenerate) {
            return response()->json($this->buildResponse($payload, $cached));
        }

        $uuid = (string) Str::uuid();
        $cached = AiIntelligenceBriefing::updateOrCreate(
            [
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
            ],
            [
                'insights' => $regenerate ? [] : ($cached?->insights ?? []),
                'ai_status' => 'queued',
                'error_message' => null,
                'queue_uuid' => $uuid,
                'provider' => null,
                'model' => null,
                'generated_by_user_id' => $request->user()?->id,
                'generated_at' => now(),
            ],
        );

        GenerateAiIntelligenceJob::dispatch($cached->id);

        $cached->refresh();

        return response()->json($this->buildResponse($payload, $cached));
    }

    public function jobStatus(Request $request, string $uuid): JsonResponse
    {
        $cached = AiIntelligenceBriefing::query()->where('queue_uuid', $uuid)->first();
        if (! $cached) {
            return response()->json(['message' => 'Job not found.', 'code' => 'AI_JOB_NOT_FOUND'], 404);
        }

        $payload = $this->data->build(
            $cached->date_from->toDateString(),
            $cached->date_to->toDateString(),
        );

        return response()->json($this->buildResponse($payload, $cached));
    }

    /** @return array{date_from: string, date_to: string} */
    private function validatedRange(Request $request): array
    {
        return $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
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
        $hasSuccessInsights = $insights !== null
            && ($cached?->ai_status === 'success')
            && filled($insights['executive_summary'] ?? null);

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
            'insights' => $hasSuccessInsights ? $insights : null,
            'insights_cached' => $hasSuccessInsights,
            'insights_generated_at' => $cached?->generated_at?->toIso8601String(),
            'ai_status' => $cached?->ai_status,
            'provider' => $cached?->provider,
            'model' => $cached?->model,
            'error_message' => $cached?->error_message,
            'queue_uuid' => $cached?->queue_uuid,
            'generated_at' => $payload['generated_at'],
        ];
    }
}
