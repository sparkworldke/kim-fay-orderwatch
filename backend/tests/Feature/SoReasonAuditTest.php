<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoReasonAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_reason_audit_endpoint_returns_taxonomy_report(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/so-reason-audit');

        $response->assertOk()
            ->assertJsonPath('taxonomy.required_sub_reason_count', 33)
            ->assertJsonStructure([
                'workflow_orders',
                'required_reason_coverage',
                'required_reasons',
                'gaps_summary',
            ]);
    }
}