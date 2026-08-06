<?php

namespace App\Services\Admin;

use App\Models\AiApiKey;

class AiConnectorService
{
    public const PROVIDERS = ['openai', 'xai', 'anthropic'];

    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function statuses(): array
    {
        return collect(self::PROVIDERS)->map(fn (string $provider) => $this->status($provider))->all();
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
            'health_status' => $record?->health_status ?? ($rawKey ? 'configured' : 'missing'),
            'has_key' => is_string($rawKey) && $rawKey !== '',
        ];
    }

    /**
     * Return the plaintext key for a provider, checking DB first then env.
     * Returns null when neither source has a key configured.
     */
    public function getKey(string $provider): ?string
    {
        $record = AiApiKey::where('provider', $provider)->first();
        $envKey = $this->envKey($provider);
        $raw = $record ? $this->encryption->decrypt($record->key_encrypted) : env($envKey);

        return (is_string($raw) && $raw !== '') ? $raw : null;
    }

    /**
     * Returns [provider, key] for the first configured provider in preference order,
     * or [first preference, null] when nothing is configured.
     *
     * @param  list<string>|null  $preferenceOrder
     * @return array{0: string, 1: string|null}
     */
    public function resolveKey(?array $preferenceOrder = null): array
    {
        $order = $preferenceOrder ?? config('ai.provider_order', self::PROVIDERS);
        if ($order === []) {
            $order = self::PROVIDERS;
        }

        foreach ($order as $provider) {
            $provider = strtolower(trim((string) $provider));
            if (! in_array($provider, self::PROVIDERS, true)) {
                continue;
            }
            $key = $this->getKey($provider);
            if ($key !== null) {
                return [$provider, $key];
            }
        }

        return [(string) ($order[0] ?? 'openai'), null];
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

    public function touchHealth(string $provider, string $status, ?string $message = null): void
    {
        $record = AiApiKey::where('provider', $provider)->first();
        if (! $record) {
            return;
        }
        $record->update([
            'health_status' => $status,
            'last_used_at' => now(),
        ]);
    }

    private function envKey(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'ANTHROPIC_API_KEY',
            'xai' => 'XAI_API_KEY',
            default => 'OPENAI_API_KEY',
        };
    }
}
