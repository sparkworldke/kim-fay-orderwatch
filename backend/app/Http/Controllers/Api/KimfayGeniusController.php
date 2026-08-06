<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiGenerationException;
use App\Http\Controllers\Controller;
use App\Models\AiGeniusBriefing;
use App\Models\User;
use App\Services\AI\KimfayGeniusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KimfayGeniusController extends Controller
{
    public function __construct(
        private readonly KimfayGeniusService $genius,
    ) {}

    public function consultants(Request $request): JsonResponse
    {
        $actor = $request->user();
        $weekStart = $this->genius->weekStart()->toDateString();

        $rows = $this->genius->listConsultants($actor)->map(function (User $u) use ($weekStart) {
            $briefing = AiGeniusBriefing::query()
                ->where('consultant_user_id', $u->id)
                ->whereDate('week_start', $weekStart)
                ->first();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'rep_code' => $u->rep_code,
                'role' => $u->role,
                'week_status' => $briefing?->ai_status,
                'has_brief' => $briefing?->ai_status === 'success',
                'generated_at' => $briefing?->generated_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'week_start' => $weekStart,
            'unlock_at' => $this->genius->unlockAt($this->genius->weekStart())->toIso8601String(),
            'data' => $rows,
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json($this->genius->show($request->user(), $user));
    }

    public function generate(Request $request, User $user): JsonResponse
    {
        $force = (bool) $request->boolean('force');

        try {
            $briefing = $this->genius->enqueue($request->user(), $user, $force);
        } catch (AiGenerationException $e) {
            return response()->json(array_merge($e->toArray(), [
                'unlock_at' => $this->genius->unlockAt($this->genius->weekStart())->toIso8601String(),
            ]), $e->httpStatus);
        }

        return response()->json([
            'week_start' => $this->genius->weekStart()->toDateString(),
            'unlock_at' => $this->genius->unlockAt($this->genius->weekStart())->toIso8601String(),
            'briefing' => $briefing,
            'consultant' => [
                'id' => $user->id,
                'name' => $user->name,
                'rep_code' => $user->rep_code,
            ],
        ]);
    }

    public function jobStatus(Request $request, string $uuid): JsonResponse
    {
        $briefing = AiGeniusBriefing::query()->where('queue_uuid', $uuid)->first();
        if (! $briefing) {
            return response()->json(['message' => 'Job not found.', 'code' => 'AI_JOB_NOT_FOUND'], 404);
        }

        $consultant = User::query()->findOrFail($briefing->consultant_user_id);
        if (! $this->genius->canAccessConsultant($request->user(), $consultant)) {
            return response()->json(['message' => 'Consultant not found.'], 404);
        }

        return response()->json([
            'briefing' => [
                'id' => $briefing->id,
                'week_start' => $briefing->week_start?->toDateString(),
                'ai_status' => $briefing->ai_status,
                'provider' => $briefing->provider,
                'model' => $briefing->model,
                'error_message' => $briefing->error_message,
                'queue_uuid' => $briefing->queue_uuid,
                'generated_at' => $briefing->generated_at?->toIso8601String(),
                'insights' => $briefing->insightPayload() ?: null,
            ],
        ]);
    }
}
