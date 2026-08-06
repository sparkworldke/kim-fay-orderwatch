<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\AiIntelligenceBriefing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-23 10:00:00', 'Africa/Nairobi'));
        config(['ai.allow_template_fallback' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_briefing_returns_metrics_without_auto_generating_insights(): void
    {
        $day = now()->subDay();
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-1001',
            'order_type' => 'SO',
            'customer_name' => 'Naivas',
            'order_date' => $day,
            'status' => 'Completed',
            'order_total' => 1500,
        ]);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/ai/intelligence?date_from='.$day->toDateString().'&date_to='.$day->toDateString())
            ->assertOk()
            ->assertJsonPath('metrics.orders.orders_received', 1)
            ->assertJsonPath('insights_cached', false)
            ->assertJsonPath('insights', null)
            ->assertJsonPath('ai_status', null);
    }

    public function test_briefing_returns_cached_insights_without_calling_ai(): void
    {
        $day = now()->subDay()->toDateString();

        AiIntelligenceBriefing::create([
            'date_from' => $day,
            'date_to' => $day,
            'insights' => [
                'executive_summary' => 'Cached executive summary.',
                'orders' => ['summary' => 'Orders cached', 'highlights' => ['One highlight']],
                'customer_behaviour' => ['summary' => 'Customers cached', 'highlights' => []],
                'predictions' => ['summary' => 'Predictions cached', 'highlights' => []],
                'actions' => ['Review top accounts'],
            ],
            'ai_status' => 'success',
            'provider' => 'openai',
            'generated_at' => now()->subHour(),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/ai/intelligence?date_from={$day}&date_to={$day}")
            ->assertOk()
            ->assertJsonPath('insights_cached', true)
            ->assertJsonPath('insights.executive_summary', 'Cached executive summary.')
            ->assertJsonPath('ai_status', 'success')
            ->assertJsonPath('provider', 'openai');
    }

    public function test_generate_without_api_key_marks_failed_not_fake_success(): void
    {
        config(['ai.allow_template_fallback' => false]);

        $day = now()->subDay();
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-1002',
            'order_type' => 'SO',
            'customer_name' => 'Carrefour',
            'order_date' => $day,
            'status' => 'Open',
            'order_total' => 800,
        ]);

        $user = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);
        $from = $day->toDateString();

        $this->actingAs($user)
            ->postJson('/api/ai/intelligence/generate', [
                'date_from' => $from,
                'date_to' => $from,
            ])
            ->assertOk()
            ->assertJsonPath('ai_status', 'failed')
            ->assertJsonPath('insights', null);

        $row = AiIntelligenceBriefing::query()
            ->whereDate('date_from', $from)
            ->whereDate('date_to', $from)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->ai_status);
        $this->assertNotEmpty($row->error_message);
    }

    public function test_generate_with_mocked_openai_saves_success(): void
    {
        config(['ai.provider_order' => ['openai']]);
        app(\App\Services\Admin\AiConnectorService::class)->store(
            'openai',
            'sk-test-key-for-unit-tests-1234567890',
            null,
        );

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'executive_summary' => 'Mocked AI summary for the period.',
                            'orders' => ['summary' => 'Orders look healthy.', 'highlights' => ['A', 'B']],
                            'customer_behaviour' => ['summary' => 'Customers stable.', 'highlights' => []],
                            'predictions' => ['summary' => 'Steady outlook.', 'highlights' => []],
                            'actions' => ['Call top accounts'],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $day = now()->subDay();
        $from = $day->toDateString();
        $user = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/ai/intelligence/generate', [
                'date_from' => $from,
                'date_to' => $from,
            ])
            ->assertOk()
            ->assertJsonPath('ai_status', 'success')
            ->assertJsonPath('insights.executive_summary', 'Mocked AI summary for the period.')
            ->assertJsonPath('provider', 'openai');
    }

    public function test_generate_without_regenerate_reuses_cache(): void
    {
        $day = now()->subDay()->toDateString();

        AiIntelligenceBriefing::create([
            'date_from' => $day,
            'date_to' => $day,
            'insights' => [
                'executive_summary' => 'Do not overwrite me.',
                'orders' => ['summary' => '', 'highlights' => []],
                'customer_behaviour' => ['summary' => '', 'highlights' => []],
                'predictions' => ['summary' => '', 'highlights' => []],
                'actions' => [],
            ],
            'ai_status' => 'success',
            'provider' => 'cached',
            'generated_at' => now()->subDay(),
        ]);

        $user = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/ai/intelligence/generate', [
                'date_from' => $day,
                'date_to' => $day,
            ])
            ->assertOk()
            ->assertJsonPath('insights.executive_summary', 'Do not overwrite me.')
            ->assertJsonPath('provider', 'cached');
    }
}
