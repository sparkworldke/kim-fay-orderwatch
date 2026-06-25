<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\AiIntelligenceBriefing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-23 10:00:00', 'Africa/Nairobi'));
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
            'order_type'          => 'SO',
            'customer_name'       => 'Naivas',
            'order_date'          => $day,
            'status'              => 'Completed',
            'order_total'         => 1500,
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
            'date_from'    => $day,
            'date_to'      => $day,
            'insights'     => [
                'executive_summary'  => 'Cached executive summary.',
                'orders'             => ['summary' => 'Orders cached', 'highlights' => ['One highlight']],
                'customer_behaviour' => ['summary' => 'Customers cached', 'highlights' => []],
                'predictions'        => ['summary' => 'Predictions cached', 'highlights' => []],
                'actions'            => ['Review top accounts'],
            ],
            'ai_status'    => 'success',
            'provider'     => 'openai',
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

    public function test_generate_saves_insights_for_date_range(): void
    {
        $day = now()->subDay();
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-1002',
            'order_type'          => 'SO',
            'customer_name'       => 'Carrefour',
            'order_date'          => $day,
            'status'              => 'Open',
            'order_total'         => 800,
        ]);

        $user = User::factory()->create();
        $from = $day->toDateString();

        $response = $this->actingAs($user)
            ->postJson('/api/ai/intelligence/generate', [
                'date_from' => $from,
                'date_to'   => $from,
            ])
            ->assertOk()
            ->assertJsonPath('insights_cached', true);

        $this->assertNotEmpty($response->json('insights.executive_summary'));

        $this->assertSame(1, AiIntelligenceBriefing::query()
            ->whereDate('date_from', $from)
            ->whereDate('date_to', $from)
            ->count());
    }

    public function test_generate_without_regenerate_reuses_cache(): void
    {
        $day = now()->subDay()->toDateString();

        AiIntelligenceBriefing::create([
            'date_from'    => $day,
            'date_to'      => $day,
            'insights'     => [
                'executive_summary'  => 'Do not overwrite me.',
                'orders'             => ['summary' => '', 'highlights' => []],
                'customer_behaviour' => ['summary' => '', 'highlights' => []],
                'predictions'        => ['summary' => '', 'highlights' => []],
                'actions'            => [],
            ],
            'ai_status'    => 'success',
            'provider'     => 'cached',
            'generated_at' => now()->subDay(),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/ai/intelligence/generate', [
                'date_from' => $day,
                'date_to'   => $day,
            ])
            ->assertOk()
            ->assertJsonPath('insights.executive_summary', 'Do not overwrite me.')
            ->assertJsonPath('provider', 'cached');
    }
}