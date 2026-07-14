<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_and_stop_impersonation(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
            'name' => 'Admin User',
            'email' => 'admin@kimfay.test',
        ]);

        $target = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
            'name' => 'Beatrice Test',
            'email' => 'beatrice@kimfay.test',
        ]);

        // Start via real Sanctum token so follow-up requests can switch bearer cleanly
        $adminPlain = $admin->createToken('api-token')->plainTextToken;

        $start = $this->withToken($adminPlain)
            ->postJson('/api/admin/impersonate', ['user_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('impersonation.active', true)
            ->assertJsonPath('impersonation.impersonator.id', $admin->id);

        $impersonationToken = $start->json('token');
        $this->assertNotEmpty($impersonationToken);

        // me() while impersonating — reset guards so Sanctum re-resolves from bearer token
        // (in-memory web guard can keep the previous request's user).
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->withToken($impersonationToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $target->id)
            ->assertJsonPath('impersonation.active', true)
            ->assertJsonPath('impersonation.impersonator.email', $admin->email);

        // Stop returns a fresh admin session
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->withToken($impersonationToken)
            ->postJson('/api/auth/impersonate/stop')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('impersonation.active', false);

        // Non-admin cannot start
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
        ]);
        $consultantPlain = $consultant->createToken('api-token')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->withToken($consultantPlain)
            ->postJson('/api/admin/impersonate', ['user_id' => $admin->id])
            ->assertForbidden();
    }

    public function test_candidates_search_is_admin_only(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
            'name' => 'Shirleen Demo',
            'email' => 'shirleen@kimfay.test',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/impersonate/candidates?q=shirleen')
            ->assertOk()
            ->assertJsonFragment(['email' => 'shirleen@kimfay.test']);

        $agent = User::factory()->create([
            'role' => 'Customer Service Agent',
            'is_active' => true,
        ]);
        Sanctum::actingAs($agent);
        $this->getJson('/api/admin/impersonate/candidates')
            ->assertForbidden();
    }
}
