<?php

namespace Tests\Unit;

use App\Services\Admin\AiConnectorService;
use App\Services\AI\LlmClient;
use App\Services\Operations\BackorderExecutiveImageService;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackorderExecutiveImageServiceTest extends TestCase
{
    public function test_it_returns_a_complete_deterministic_fallback_when_openai_is_unavailable(): void
    {
        $connector = Mockery::mock(AiConnectorService::class);
        $connector->shouldReceive('getKey')->once()->with('openai')->andReturnNull();
        $llm = Mockery::mock(LlmClient::class);
        $llm->shouldReceive('chatJson')->once()->andThrow(new RuntimeException('provider unavailable'));

        $result = (new BackorderExecutiveImageService($connector, $llm))->enrich([
            'metrics' => ['revenue_at_risk' => 1000],
            'breakdowns' => [],
        ]);

        $this->assertNull($result['background_image']);
        $this->assertSame('fallback', $result['ai_status']['image']);
        $this->assertSame('fallback', $result['ai_status']['narrative']);
        $this->assertCount(3, $result['narrative']);
    }
}
