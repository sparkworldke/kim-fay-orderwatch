<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Services\Admin\AiConnectorService;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiConnectorController extends Controller
{
    public function __construct(
        private readonly AiConnectorService $ai,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->ai->statuses());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(['openai', 'anthropic'])],
            'key' => ['required', 'string', 'min:20'],
        ]);

        $record = $this->ai->store($validated['provider'], $validated['key'], $request->user()?->id);

        $this->audit->log('ai_key_saved', 'ai_api_key', $record->id, [
            'provider' => $validated['provider'],
            'key' => $validated['key'],
        ], $request->user()?->id, $request->ip());

        return response()->json($this->ai->status($validated['provider']), 201);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $record = AiApiKey::findOrFail($id);
        $provider = $record->provider;
        $record->delete();

        $this->audit->log('ai_key_deleted', 'ai_api_key', $id, [
            'provider' => $provider,
        ], $request->user()?->id, $request->ip());

        return response()->json($this->ai->status($provider));
    }
}
