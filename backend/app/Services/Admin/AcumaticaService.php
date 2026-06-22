<?php

namespace App\Services\Admin;

use App\Models\AcumaticaConfig;
use App\Models\AcumaticaSyncLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class AcumaticaService
{
    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function config(): AcumaticaConfig
    {
        $config = AcumaticaConfig::first();

        if ($config) {
            return $config;
        }

        $baseUrl = rtrim(env('ACUMATICA_BASE_URL', 'https://kimfay.acumatica.com'), '/');

        return AcumaticaConfig::create([
            'base_url' => $baseUrl,
            'endpoint' => env('ACUMATICA_ENDPOINT', 'IpayV2'),
            'version' => env('ACUMATICA_VERSION', '22.200.001'),
            'tenant' => env('ACUMATICA_TENANT', 'Kim-Fay Limited'),
            'grant_type' => 'password',
            'scope' => 'api',
            'username' => env('ACUMATICA_USERNAME', ''),
            'password_encrypted' => $this->encryption->encrypt(env('ACUMATICA_PASSWORD', '')),
            'client_id_encrypted' => env('ACUMATICA_CLIENT_ID') ? $this->encryption->encrypt(env('ACUMATICA_CLIENT_ID')) : null,
            'client_secret_encrypted' => env('ACUMATICA_CLIENT_SECRET') ? $this->encryption->encrypt(env('ACUMATICA_CLIENT_SECRET')) : null,
            'token_url' => env('ACUMATICA_TOKEN_URL', $baseUrl . '/identity/connect/token'),
            'endpoint_version' => env('ACUMATICA_VERSION', '22.200.001'),
            'health_status' => 'unchecked',
        ]);
    }

    public function summary(): array
    {
        $config = $this->config();

        return [
            'config' => $this->present($config),
            'sync_logs' => AcumaticaSyncLog::orderByDesc('started_at')->limit(20)->get(),
        ];
    }

    public function update(array $data): AcumaticaConfig
    {
        $config = $this->config();

        foreach (['base_url', 'endpoint', 'version', 'tenant', 'username', 'token_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $config->{$field} = $data[$field];
            }
        }

        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            $config->password_encrypted = $this->encryption->encrypt($data['password']);
        }

        if (array_key_exists('client_id', $data)) {
            $config->client_id_encrypted = ($data['client_id'] !== null && $data['client_id'] !== '') ? $this->encryption->encrypt($data['client_id']) : null;
        }

        if (array_key_exists('client_secret', $data)) {
            $config->client_secret_encrypted = ($data['client_secret'] !== null && $data['client_secret'] !== '') ? $this->encryption->encrypt($data['client_secret']) : null;
        }

        $config->save();

        return $config;
    }

    public function validateCredentials(): array
    {
        $config = $this->config();
        $started = microtime(true);

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($config->token_url, [
                    'grant_type' => $config->grant_type,
                    'client_id' => $this->encryption->decrypt($config->client_id_encrypted) ?? '',
                    'client_secret' => $this->encryption->decrypt($config->client_secret_encrypted) ?? '',
                    'username' => $config->username,
                    'password' => $this->encryption->decrypt($config->password_encrypted) ?? '',
                    'scope' => $config->scope,
                ]);

            $responseMs = (int) round((microtime(true) - $started) * 1000);
            $success = $response->successful();

            $config->forceFill([
                'health_status' => $success ? 'connected' : 'error',
                'last_validated_at' => $success ? now() : $config->last_validated_at,
            ])->save();

            if (! $success) {
                StructuredLogger::write('error', 'acumatica', 'auth_failure', [
                    'status' => $response->status(),
                    'message' => $response->reason(),
                    'url' => $config->token_url,
                ]);
            }

            return [
                'success' => $success,
                'message' => $success ? 'Acumatica credentials validated.' : 'Acumatica validation failed.',
                'response_ms' => $responseMs,
            ];
        } catch (Throwable $exception) {
            $config->forceFill(['health_status' => 'error'])->save();
            StructuredLogger::write('error', 'acumatica', 'auth_failure', [
                'message' => $exception->getMessage(),
                'url' => $config->token_url,
            ]);

            return [
                'success' => false,
                'message' => str_contains(strtolower($exception->getMessage()), 'timed out')
                    ? 'Acumatica authentication timed out after 15s'
                    : 'Acumatica validation failed.',
                'response_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    public function present(AcumaticaConfig $config): array
    {
        return [
            'id' => $config->id,
            'base_url' => $config->base_url,
            'endpoint' => $config->endpoint,
            'version' => $config->version,
            'tenant' => $config->tenant,
            'username' => $config->username,
            'token_url' => $config->token_url,
            'client_id_preview' => $this->encryption->mask($this->encryption->decrypt($config->client_id_encrypted)),
            'client_secret_preview' => $this->encryption->mask($this->encryption->decrypt($config->client_secret_encrypted)),
            'password_preview' => $this->encryption->mask($this->encryption->decrypt($config->password_encrypted), 2),
            'endpoint_version' => $config->endpoint_version,
            'last_validated_at' => $config->last_validated_at,
            'health_status' => $config->health_status,
        ];
    }
}
