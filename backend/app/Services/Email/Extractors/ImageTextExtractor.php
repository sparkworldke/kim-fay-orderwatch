<?php

namespace App\Services\Email\Extractors;

use App\Services\Admin\AiConnectorService;
use Illuminate\Support\Facades\Http;
use Throwable;

class ImageTextExtractor
{
    public function __construct(private readonly AiConnectorService $ai) {}

    private const SUPPORTED_TYPES = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'image/webp', 'image/bmp', 'image/tiff',
    ];

    /**
     * Extract text from an image using AI vision (Claude or OpenAI).
     * Returns the raw AI response text — the caller passes this to PO extractor.
     */
    public function extract(string $imageBytes, string $contentType): ?string
    {
        [$provider, $apiKey] = $this->ai->resolveKey();

        if (! $apiKey) {
            return null;
        }

        $base64      = base64_encode($imageBytes);
        $mimeType    = strtolower($contentType);
        $prompt      = 'Extract all text visible in this image. Focus on any purchase order numbers, PO numbers, order references, or similar identifiers. Return only the extracted text, nothing else.';

        try {
            return $provider === 'anthropic'
                ? $this->viaAnthropic($apiKey, $base64, $mimeType, $prompt)
                : $this->viaOpenAi($apiKey, $base64, $mimeType, $prompt);
        } catch (Throwable) {
            return null;
        }
    }

    public function supports(string $contentType): bool
    {
        return in_array(strtolower($contentType), self::SUPPORTED_TYPES, true)
            || str_starts_with(strtolower($contentType), 'image/');
    }

    private function viaAnthropic(string $key, string $base64, string $mime, string $prompt): ?string
    {
        // Anthropic supports: image/jpeg, image/png, image/gif, image/webp
        $supportedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (! in_array($mime, $supportedMimes, true)) {
            $mime = 'image/jpeg'; // best guess fallback
        }

        $response = Http::withHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1000,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                    ['type' => 'text',  'text'   => $prompt],
                ],
            ]],
        ]);

        return $response->successful() ? ($response->json('content.0.text') ?? null) : null;
    }

    private function viaOpenAi(string $key, string $base64, string $mime, string $prompt): ?string
    {
        $response = Http::withToken($key)->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
            'model'      => 'gpt-4o-mini',
            'max_tokens' => 500,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}", 'detail' => 'high']],
                    ['type' => 'text',      'text'      => $prompt],
                ],
            ]],
        ]);

        return $response->successful() ? ($response->json('choices.0.message.content') ?? null) : null;
    }
}
