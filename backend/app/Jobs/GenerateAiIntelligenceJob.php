<?php

namespace App\Jobs;

use App\Exceptions\AiGenerationException;
use App\Models\AiIntelligenceBriefing;
use App\Services\AI\AiIntelligenceDataService;
use App\Services\AI\AiIntelligenceInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateAiIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public readonly int $briefingId)
    {
    }

    public function handle(
        AiIntelligenceDataService $data,
        AiIntelligenceInsightService $insights,
    ): void {
        $briefing = AiIntelligenceBriefing::query()->find($this->briefingId);
        if (! $briefing) {
            return;
        }

        $briefing->update(['ai_status' => 'running', 'error_message' => null]);

        try {
            $payload = $data->build(
                $briefing->date_from->toDateString(),
                $briefing->date_to->toDateString(),
            );
            $ai = $insights->generate($payload);

            $briefing->update([
                'insights' => [
                    'executive_summary' => $ai['executive_summary'] ?? '',
                    'orders' => $ai['orders'] ?? ['summary' => '', 'highlights' => []],
                    'customer_behaviour' => $ai['customer_behaviour'] ?? ['summary' => '', 'highlights' => []],
                    'predictions' => $ai['predictions'] ?? ['summary' => '', 'highlights' => []],
                    'actions' => $ai['actions'] ?? [],
                ],
                'ai_status' => 'success',
                'provider' => $ai['provider'] ?? null,
                'model' => $ai['model'] ?? null,
                'error_message' => null,
                'generated_at' => now(),
            ]);
        } catch (AiGenerationException $e) {
            $briefing->update([
                'ai_status' => 'failed',
                'error_message' => $e->getMessage(),
                'provider' => $e->provider,
                'generated_at' => now(),
            ]);
        } catch (Throwable $e) {
            $briefing->update([
                'ai_status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'generated_at' => now(),
            ]);
            throw $e;
        }
    }
}
