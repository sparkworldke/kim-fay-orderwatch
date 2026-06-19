<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AcumaticaService;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcumaticaController extends Controller
{
    public function __construct(
        private readonly AcumaticaService $acumatica,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->acumatica->summary());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:500'],
            'endpoint' => ['required', 'string', 'max:100'],
            'version' => ['required', 'string', 'max:50'],
            'tenant' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'token_url' => ['required', 'url', 'max:500'],
            'password' => ['nullable', 'string'],
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
        ]);

        $before = $this->acumatica->present($this->acumatica->config());
        $config = $this->acumatica->update($validated);
        $after = $this->acumatica->present($config);

        $this->audit->log('acumatica_config_updated', 'acumatica_config', $config->id, [
            'before' => $before,
            'after' => $after,
        ], $request->user()?->id, $request->ip());

        return response()->json(['config' => $after]);
    }

    public function validateCredentials(Request $request): JsonResponse
    {
        $result = $this->acumatica->validateCredentials();

        if (! $result['success']) {
            $this->audit->log('acumatica_auth_failure', 'acumatica_config', $this->acumatica->config()->id, [
                'message' => $result['message'],
            ], $request->user()?->id, $request->ip());
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
