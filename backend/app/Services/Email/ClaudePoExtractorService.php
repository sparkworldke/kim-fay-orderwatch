<?php

namespace App\Services\Email;

use App\Contracts\PoExtractorContract;
use App\Models\AiApiKey;
use App\Services\Admin\EncryptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudePoExtractorService implements PoExtractorContract
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL   = 'claude-haiku-4-5-20251001'; // Fast + cheap for extraction

    private ?string $resolvedKey = null;

    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function getName(): string
    {
        return 'ai_claude';
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

        $prompt = $this->buildPrompt($text, $hints);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(20)->post(self::API_URL, [
                'model'      => self::MODEL,
                'max_tokens' => 100,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                Log::warning('Claude PO extraction failed', ['status' => $response->status()]);
                return null;
            }

            $raw = trim($response->json('content.0.text') ?? '');
            return $this->parseResponse($raw);
        } catch (\Throwable $e) {
            Log::error('Claude PO extraction exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildPrompt(string $text, array $hints): string
    {
        $context = '';
        if (! empty($hints['sender'])) {
            $context = "The email was sent by: {$hints['sender']}\n";
        }

        return <<<TXT
        {$context}
        Extract the Purchase Order (PO) number from the following text. Return ONLY the PO number, nothing else.
        Known formats:
        - Naivas: starts with P followed by 7-10 digits, e.g. P042539739
        - Carrefour: 8 standalone digits, e.g. 26020742
        - QuickMart: NNN-NNNNNNNN format, e.g. 067-00027749
        - Chandarana: 13-digit number starting with 1, e.g. 1001120070938

        If you cannot find a PO number, respond with exactly: NONE

        Text:
        {$text}
        TXT;
    }

    private function parseResponse(string $raw): ?ExtractionResult
    {
        $cleaned = strtoupper(trim($raw));

        if ($cleaned === '' || $cleaned === 'NONE') {
            return null;
        }

        // Validate it looks like a real PO number (not a sentence)
        if (strlen($cleaned) > 50 || str_word_count($cleaned) > 3) {
            return null;
        }

        return new ExtractionResult(
            poNumber:   $cleaned,
            method:     'ai_claude',
            confidence: 75,
            rawMatch:   $raw,
        );
    }

    private function getApiKey(): ?string
    {
        if ($this->resolvedKey !== null) {
            return $this->resolvedKey;
        }

        $record = AiApiKey::where('provider', 'anthropic')->first();
        if ($record) {
            $this->resolvedKey = $this->encryption->decrypt($record->key_encrypted);
            return $this->resolvedKey;
        }

        $env = env('ANTHROPIC_API_KEY');
        $this->resolvedKey = $env ?: null;
        return $this->resolvedKey;
    }
}
