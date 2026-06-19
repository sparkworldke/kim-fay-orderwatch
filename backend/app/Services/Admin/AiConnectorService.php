<?php

namespace App\Services\Admin;

use App\Models\AiApiKey;

class AiConnectorService
{
    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function statuses(): array
    {
        return collect(['openai', 'anthropic'])->map(fn (string $provider) => $this->status($provider))->all();
    }

    public function status(string $provider): array
    {
        $record = AiApiKey::where('provider', $provider)->first();
        $envKey = $this->envKey($provider);
        $rawKey = $record ? $this->encryption->decrypt($record->key_encrypted) : env($envKey);

        return [
            'id' => $record?->id,
            'provider' => $provider,
            'source' => $record ? 'database' : 'environment',
            'masked_preview' => $this->encryption->mask($rawKey),
            'last_used_at' => $record?->last_used_at,
            'health_status' => $record?->health_status ?? ($rawKey ? 'healthy' : 'unchecked'),
        ];
    }

    public function store(string $provider, string $key, ?int $userId): AiApiKey
    {
        return AiApiKey::updateOrCreate(
            ['provider' => $provider],
            [
                'key_encrypted' => $this->encryption->encrypt($key),
                'created_by' => $userId,
                'health_status' => 'healthy',
            ],
        );
    }

    private function envKey(string $provider): string
    {
        return $provider === 'anthropic' ? 'ANTHROPIC_API_KEY' : 'OPENAI_API_KEY';
    }
}
