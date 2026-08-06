<?php

namespace Tests\Feature;

use App\Models\AiGeniusBriefing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KimfayGeniusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00', 'Africa/Nairobi'));
        config(['ai.allow_template_fallback' => false, 'ai.provider_order' => ['openai']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lists_consultants_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);
        User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P100',
            'is_active' => true,
            'name' => 'Jane Consultant',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/ai/genius/consultants')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Jane Consultant', 'rep_code' => 'P100']);
    }

    public function test_weekly_lock_after_success(): void
    {
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
                            'executive_summary' => 'Coach Jane this week.',
                            'portfolio' => ['summary' => 'Solid book.', 'highlights' => ['Top accounts stable']],
                            'risks' => ['summary' => 'Watch dormant.', 'highlights' => []],
                            'predictions' => ['summary' => 'Steady.', 'highlights' => []],
                            'actions' => ['Visit top 3 dormant accounts'],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P200',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/ai/genius/consultants/{$consultant->id}/generate")
            ->assertOk()
            ->assertJsonPath('briefing.ai_status', 'success');

        $this->actingAs($admin)
            ->postJson("/api/ai/genius/consultants/{$consultant->id}/generate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'AI_GENIUS_WEEKLY_LOCK');

        $this->assertSame(1, AiGeniusBriefing::query()->where('consultant_user_id', $consultant->id)->count());
    }

    public function test_failed_generation_does_not_lock_week(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P300',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/ai/genius/consultants/{$consultant->id}/generate")
            ->assertOk()
            ->assertJsonPath('briefing.ai_status', 'failed');

        // Retry allowed
        $this->actingAs($admin)
            ->postJson("/api/ai/genius/consultants/{$consultant->id}/generate")
            ->assertOk()
            ->assertJsonPath('briefing.ai_status', 'failed');
    }

    public function test_any_authenticated_user_can_view_all_sales_books(): void
    {
        $a = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P1',
            'is_active' => true,
        ]);
        $b = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P2',
            'is_active' => true,
            'name' => 'Other Book',
        ]);
        $cs = User::factory()->create([
            'role' => 'Customer Service Agent',
            'is_active' => true,
            'is_consultant' => false,
            'rep_code' => null,
        ]);

        $this->actingAs($a)
            ->getJson("/api/ai/genius/consultants/{$b->id}")
            ->assertOk()
            ->assertJsonPath('consultant.rep_code', 'P2');

        $this->actingAs($cs)
            ->getJson('/api/ai/genius/consultants')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Other Book', 'rep_code' => 'P2']);
    }

    public function test_consultant_can_generate_genius_despite_view_only_middleware(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P900',
            'is_active' => true,
        ]);

        // No API key → failed status, but must not be 403 read-only.
        $this->actingAs($consultant)
            ->postJson("/api/ai/genius/consultants/{$consultant->id}/generate")
            ->assertOk()
            ->assertJsonPath('briefing.ai_status', 'failed');
    }

    public function test_manager_who_also_sells_sees_self_in_genius_list(): void
    {
        $manager = User::factory()->create([
            'role' => 'Sales Manager',
            'department_role' => 'hod',
            'org_level' => 'hod',
            'is_consultant' => true,
            'rep_code' => 'P415',
            'is_active' => true,
            'name' => 'Dual Role Manager',
        ]);

        // Dual-role: must include self even when also a team manager (hasSalesBook)
        $this->actingAs($manager)
            ->getJson('/api/ai/genius/consultants')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Dual Role Manager', 'rep_code' => 'P415']);

        $this->actingAs($manager)
            ->getJson("/api/ai/genius/consultants/{$manager->id}")
            ->assertOk()
            ->assertJsonPath('consultant.rep_code', 'P415');
    }

    public function test_executive_with_rep_code_can_open_own_genius(): void
    {
        $exec = User::factory()->create([
            'role' => 'Executive',
            'department_role' => 'executive',
            'org_level' => 'executive',
            'is_consultant' => false,
            'rep_code' => 'PEX1',
            'is_active' => true,
            'name' => 'Selling Executive',
        ]);

        $this->actingAs($exec)
            ->getJson("/api/ai/genius/consultants/{$exec->id}")
            ->assertOk()
            ->assertJsonPath('consultant.name', 'Selling Executive');
    }
}
