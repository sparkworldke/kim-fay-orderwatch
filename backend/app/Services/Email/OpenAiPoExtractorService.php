<?php

namespace App\Services\Email;

use App\Contracts\PoExtractorContract;
use App\Models\AiApiKey;
use App\Services\Admin\EncryptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiPoExtractorService implements PoExtractorContract
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL   = 'gpt-4o-mini';

    private ?string $resolvedKey = null;

    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function getName(): string
    {
        return 'ai_openai';
    }

    public function isAvailable(): bool
    {
        return $this->getApiKey() !== null;
    }

    public function extractFromText(string $text, array $hints = []): ?ExtractionResult
    {
        $key = $this->getApiKey();
        if (! $key) {
            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(20)
                ->post(self::API_URL, [
                    'model'      => self::MODEL,
                    'max_tokens' => 100,
                    'messages'   => [
                        [
                            'role'    => 'system',
                            'content' => 'You are a PO number extractor. Extract the Purchase Order number from the provided text and return ONLY the PO number. If none found, return NONE.',
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI PO extraction failed', ['status' => $response->status()]);
                return null;
            }

            $raw = trim($response->json('choices.0.message.content') ?? '');
            return $this->parseResponse($raw);
        } catch (\Throwable $e) {
            Log::error('OpenAI PO extraction exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function parseResponse(string $raw): ?ExtractionResult
    {
        $cleaned = strtoupper(trim($raw));
        if ($cleaned === '' || $cleaned === 'NONE') {
            return null;
        }
        if (strlen($cleaned) > 50 || str_word_count($cleaned) > 3) {
            return null;
        }
        return new ExtractionResult(
            poNumber:   $cleaned,
            method:     'ai_openai',
            confidence: 72,
            rawMatch:   $raw,
        );
    }

    private function getApiKey(): ?string
    {
        if ($this->resolvedKey !== null) {
            return $this->resolvedKey;
        }
        $record = AiApiKey::where('provider', 'openai')->first();
        if ($record) {
            $this->resolvedKey = $this->encryption->decrypt($record->key_encrypted);
            return $this->resolvedKey;
        }
        $env = env('OPENAI_API_KEY');
        $this->resolvedKey = $env ?: null;
        return $this->resolvedKey;
    }
}
