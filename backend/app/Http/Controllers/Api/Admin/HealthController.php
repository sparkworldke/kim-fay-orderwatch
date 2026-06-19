<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Models\MailboxAccount;
use App\Services\Admin\AcumaticaService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(private readonly AcumaticaService $acumatica)
    {
    }

    public function index(): JsonResponse
    {
        $config = $this->acumatica->config();

        return response()->json([
            'outlook_oauth' => [
                'status' => MailboxAccount::where('status', 'connected')->exists() ? 'connected' : 'unchecked',
                'last_checked_at' => MailboxAccount::max('updated_at'),
            ],
            'openai' => $this->aiHealth('openai'),
            'anthropic' => $this->aiHealth('anthropic'),
            'acumatica' => [
                'status' => $config->health_status,
                'last_checked_at' => $config->last_validated_at,
            ],
        ]);
    }

    private function aiHealth(string $provider): array
    {
        $record = AiApiKey::where('provider', $provider)->first();
        $envKey = $provider === 'anthropic' ? 'ANTHROPIC_API_KEY' : 'OPENAI_API_KEY';

        return [
            'status' => $record?->health_status ?? (env($envKey) ? 'healthy' : 'unchecked'),
            'last_checked_at' => $record?->last_used_at,
        ];
    }
}
