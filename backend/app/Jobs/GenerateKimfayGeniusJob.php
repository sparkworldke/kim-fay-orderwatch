<?php

namespace App\Jobs;

use App\Exceptions\AiGenerationException;
use App\Models\AiGeniusBriefing;
use App\Services\AI\KimfayGeniusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateKimfayGeniusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public readonly int $briefingId)
    {
    }

    public function handle(KimfayGeniusService $genius): void
    {
        $briefing = AiGeniusBriefing::query()->find($this->briefingId);
        if (! $briefing) {
            return;
        }

        $briefing->update(['ai_status' => 'running', 'error_message' => null]);

        try {
            $result = $genius->runGeneration($briefing);
            $briefing->update([
                'insights' => $result['insights'],
                'metrics_snapshot' => $result['metrics_snapshot'],
                'ai_status' => 'success',
                'provider' => $result['provider'],
                'model' => $result['model'],
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
