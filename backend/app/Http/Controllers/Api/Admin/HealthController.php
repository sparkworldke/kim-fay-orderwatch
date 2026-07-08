<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Models\MailboxAccount;
use App\Models\SystemSetting;
use App\Services\Admin\AcumaticaService;
use App\Services\Admin\MailSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __construct(
        private readonly AcumaticaService $acumatica,
        private readonly MailSettingsService $mailSettings,
    ) {}

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
            'mail_delivery' => [
                'status' => $this->mailConfigured() ? 'healthy' : 'unchecked',
                'last_checked_at' => SystemSetting::query()->where('key', SystemSetting::MAIL_MAILER)->value('updated_at'),
                'mailer' => config('mail.default'),
            ],
        ]);
    }

    public function mailSettings(): JsonResponse
    {
        return response()->json($this->mailSettings->present());
    }

    public function updateMailSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mailer' => ['sometimes', 'string', 'in:smtp,resend'],
            'smtp_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_scheme' => ['sometimes', 'nullable', 'string', 'in:tls,ssl'],
            'smtp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'smtp_password' => ['sometimes', 'nullable', 'string', 'max:500'],
            'from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'resend_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->mailSettings->update($validated));
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

    private function mailConfigured(): bool
    {
        return match (config('mail.default')) {
            'smtp' => $this->mailSettings->smtpConfigured(),
            'resend' => (bool) config('services.resend.key'),
            default => false,
        };
    }
}
