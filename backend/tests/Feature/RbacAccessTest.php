<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_service_can_access_mail_and_admin_non_sensitive_modules(): void
    {
        $user = User::factory()->create(['role' => 'Customer Service Agent', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/health')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/emails')
            ->assertOk();
    }

    public function test_customer_service_cannot_access_acumatica_or_ai_keys_or_roles_modules(): void
    {
        $user = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/ai-keys')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/roles')
            ->assertForbidden();
    }

    public function test_non_admin_non_customer_service_is_view_only_and_has_modules_hidden(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/categories')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/emails')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/order-match/queue')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/order-match/run')
            ->assertForbidden();
    }
}

