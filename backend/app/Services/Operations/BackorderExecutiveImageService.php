<?php

namespace App\Services\Operations;

use App\Services\Admin\AiConnectorService;
use App\Services\AI\LlmClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackorderExecutiveImageService
{
    public function __construct(
        private readonly AiConnectorService $connector,
        private readonly LlmClient $llm,
    ) {}

    /** @param array<string,mixed> $report */
    public function enrich(array $report): array
    {
        $narrative = $this->fallbackNarrative();
        $background = null;
        $status = ['narrative' => 'fallback', 'image' => 'fallback', 'model' => null];

        try {
            $response = $this->llm->chatJson(
                'You are a concise executive operations analyst. Return JSON only. Never invent or repeat numbers, amounts, percentages, dates, customer names, product codes, or statistics. Produce exactly three observations with keys title, insight, action. Focus on release, supply, and customer follow-up decisions.',
                'Write qualitative observations from this trusted backorder summary. The dashboard renders all figures separately: '.json_encode($report, JSON_UNESCAPED_SLASHES),
                ['openai'],
            );
            $decoded = json_decode($response['content'], true, 512, JSON_THROW_ON_ERROR);
            $candidate = $this->validateNarrative($decoded['observations'] ?? null);
            if ($candidate !== null) {
                $narrative = $candidate;
                $status['narrative'] = 'generated';
                $status['model'] = $response['model'];
            }
        } catch (Throwable $e) {
            Log::warning('Backorder executive narrative fallback used', ['error' => $this->safeError($e)]);
        }

        try {
            $key = $this->connector->getKey('openai');
            if ($key !== null) {
                $model = (string) config('ai.image_model', 'gpt-image-2');
                $response = Http::withToken($key)
                    ->timeout(max(30, (int) config('ai.image_timeout_seconds', 150)))
                    ->retry(2, 500, throw: false)
                    ->post('https://api.openai.com/v1/images/generations', [
                        'model' => $model,
                        'prompt' => 'Create a premium abstract landscape background for a Kim-Fay Sight executive operations report. Deep navy, teal and restrained warm coral accents, subtle supply-chain flow shapes, ample calm negative space, professional East African FMCG leadership aesthetic. Absolutely no text, numbers, letters, logos, icons, charts, tables, labels or watermarks.',
                        'size' => '1536x1024',
                        'quality' => (string) config('ai.image_quality', 'medium'),
                        'output_format' => 'png',
                    ]);
                if ($response->successful() && is_string($response->json('data.0.b64_json'))) {
                    $background = 'data:image/png;base64,'.$response->json('data.0.b64_json');
                    $status['image'] = 'generated';
                    $status['model'] = $model;
                    $this->connector->touchHealth('openai', 'healthy');
                } else {
                    $this->connector->touchHealth('openai', 'error');
                    Log::warning('Backorder executive image fallback used', ['status' => $response->status()]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Backorder executive image fallback used', ['error' => $this->safeError($e)]);
        }

        return ['narrative' => $narrative, 'background_image' => $background, 'ai_status' => $status];
    }

    private function validateNarrative(mixed $items): ?array
    {
        if (! is_array($items) || count($items) !== 3) return null;
        $validated = [];
        foreach ($items as $item) {
            if (! is_array($item)) return null;
            $row = [];
            foreach (['title', 'insight', 'action'] as $key) {
                $value = trim((string) ($item[$key] ?? ''));
                if ($value === '' || mb_strlen($value) > 180 || preg_match('/[0-9%$£€]|KES/i', $value)) return null;
                $row[$key] = $value;
            }
            $validated[] = $row;
        }
        return $validated;
    }

    private function fallbackNarrative(): array
    {
        return [
            ['title' => 'Release available stock', 'insight' => 'Stock-covered exposure is the fastest path to reducing the open-order queue.', 'action' => 'Prioritize picking, allocation and shipment release.'],
            ['title' => 'Close supply gaps', 'insight' => 'Blocked manufactured and partner lines require different supply owners.', 'action' => 'Route manufactured gaps to production and trading gaps to procurement.'],
            ['title' => 'Protect customer commitments', 'insight' => 'Older and concentrated exposures need proactive commercial follow-up.', 'action' => 'Confirm delivery plans with the highest-priority customers.'],
        ];
    }

    private function safeError(Throwable $e): string
    {
        return mb_substr(preg_replace('/sk-[A-Za-z0-9_-]+/', '[REDACTED]', $e->getMessage()) ?? 'AI provider error', 0, 300);
    }
}
