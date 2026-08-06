<?php

namespace App\Services\AI;

use App\Exceptions\AiGenerationException;
use App\Services\Admin\AiConnectorService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Unified chat-completion client for OpenAI, xAI (Grok), and Anthropic.
 */
class LlmClient
{
    public function __construct(
        private readonly AiConnectorService $connector,
    ) {}

    /**
     * @return array{provider: string, model: string, content: string}
     *
     * @throws AiGenerationException
     */
    public function chatJson(string $system, string $user, ?array $providerOrder = null): array
    {
        [$provider, $apiKey] = $this->connector->resolveKey($providerOrder ?? config('ai.provider_order', ['openai', 'xai', 'anthropic']));

        if (! is_string($apiKey) || $apiKey === '') {
            throw new AiGenerationException(
                'No AI API key configured. Add a key under Administration → AI Connector (OpenAI, xAI, or Anthropic).',
                'AI_KEY_MISSING',
                503,
            );
        }

        $timeout = max(30, (int) config('ai.http_timeout_seconds', 120));
        $maxTokens = max(256, (int) config('ai.max_tokens', 1800));

        try {
            $content = match ($provider) {
                'anthropic' => $this->callAnthropic($apiKey, $system, $user, $timeout, $maxTokens),
                'xai' => $this->callOpenAiCompatible(
                    $apiKey,
                    'https://api.x.ai/v1/chat/completions',
                    (string) config('ai.xai_model', 'grok-4.5'),
                    $system,
                    $user,
                    $timeout,
                    $maxTokens,
                    true,
                ),
                default => $this->callOpenAiCompatible(
                    $apiKey,
                    'https://api.openai.com/v1/chat/completions',
                    (string) config('ai.openai_model', 'gpt-4o-mini'),
                    $system,
                    $user,
                    $timeout,
                    $maxTokens,
                    true,
                ),
            };
        } catch (AiGenerationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AiGenerationException(
                $this->sanitize($e->getMessage()),
                'AI_PROVIDER_ERROR',
                502,
                $provider,
            );
        }

        if (trim($content) === '') {
            throw new AiGenerationException(
                'AI provider returned an empty response.',
                'AI_PROVIDER_ERROR',
                502,
                $provider,
            );
        }

        $model = match ($provider) {
            'anthropic' => (string) config('ai.anthropic_model'),
            'xai' => (string) config('ai.xai_model'),
            default => (string) config('ai.openai_model'),
        };

        return [
            'provider' => $provider,
            'model' => $model,
            'content' => $content,
        ];
    }

    /**
     * Lightweight health probe for admin.
     *
     * @return array{ok: bool, provider: string, model: string|null, error: string|null, latency_ms: int}
     */
    public function healthCheck(string $provider): array
    {
        $start = microtime(true);
        $key = $this->connector->getKey($provider);
        if ($key === null) {
            return [
                'ok' => false,
                'provider' => $provider,
                'model' => null,
                'error' => 'No API key configured for this provider.',
                'latency_ms' => 0,
            ];
        }

        try {
            $this->chatJson(
                'Reply with valid JSON only: {"ok":true}',
                'health check',
                [$provider],
            );

            return [
                'ok' => true,
                'provider' => $provider,
                'model' => match ($provider) {
                    'anthropic' => (string) config('ai.anthropic_model'),
                    'xai' => (string) config('ai.xai_model'),
                    default => (string) config('ai.openai_model'),
                },
                'error' => null,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'provider' => $provider,
                'model' => null,
                'error' => $this->sanitize($e->getMessage()),
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function callOpenAiCompatible(
        string $key,
        string $url,
        string $model,
        string $system,
        string $user,
        int $timeout,
        int $maxTokens,
        bool $jsonObject,
    ): string {
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => $maxTokens,
        ];
        if ($jsonObject) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->withRetry(function () use ($key, $url, $body, $timeout) {
            return Http::withToken($key)->timeout($timeout)->post($url, $body);
        });

        if (! $response->successful()) {
            $msg = $response->json('error.message') ?? $response->body();
            throw new AiGenerationException(
                $this->sanitize(is_string($msg) ? $msg : 'Provider HTTP '.$response->status()),
                'AI_PROVIDER_ERROR',
                502,
            );
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    private function callAnthropic(string $key, string $system, string $user, int $timeout, int $maxTokens): string
    {
        $response = $this->withRetry(function () use ($key, $system, $user, $timeout, $maxTokens) {
            return Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ])->timeout($timeout)->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('ai.anthropic_model'),
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
                'max_tokens' => $maxTokens,
            ]);
        });

        if (! $response->successful()) {
            $msg = $response->json('error.message') ?? $response->body();
            throw new AiGenerationException(
                $this->sanitize(is_string($msg) ? $msg : 'Anthropic HTTP '.$response->status()),
                'AI_PROVIDER_ERROR',
                502,
                'anthropic',
            );
        }

        return (string) ($response->json('content.0.text') ?? '');
    }

    /** @param  callable(): \Illuminate\Http\Client\Response  $fn */
    private function withRetry(callable $fn): \Illuminate\Http\Client\Response
    {
        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $last = $fn();
            if ($last->successful()) {
                return $last;
            }
            $status = $last->status();
            if (! in_array($status, [429, 500, 502, 503, 504], true) || $attempt === 1) {
                return $last;
            }
            usleep(400_000 * ($attempt + 1));
        }

        return $last;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(sk-[a-zA-Z0-9_-]{10,})/', '[REDACTED_KEY]', $message) ?? $message;
        $message = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[REDACTED]', $message) ?? $message;

        return mb_substr(trim($message), 0, 500);
    }
}
