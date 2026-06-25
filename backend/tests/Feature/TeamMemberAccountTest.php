<?php

namespace Tests\Feature;

use App\Mail\TeamMemberAccountMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamMemberAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_team_member_and_send_welcome_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'New Agent',
                'email' => 'agent@kimfay.test',
                'role' => 'Customer Service Agent',
                'phone_number' => '+254700000000',
            ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'agent@kimfay.test')
            ->assertJsonPath('role', 'Customer Service Agent');

        $this->assertDatabaseHas('users', [
            'email' => 'agent@kimfay.test',
            'role' => 'Customer Service Agent',
            'is_active' => true,
        ]);

        Mail::assertSent(TeamMemberAccountMail::class, function (TeamMemberAccountMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('agent@kimfay.test')
                && str_contains($html, 'https://orderwatch.test/app')
                && str_contains($html, 'https://orderwatch.test/auth')
                && ! str_contains($html, 'https://orderwatch.test/login')
                && str_contains($html, 'Customer Service Agent')
                && ! str_contains($html, 'api.orderwatch.test');
        });
    }

    public function test_non_admin_cannot_create_team_member(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'Customer Service Agent',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Blocked User',
                'email' => 'blocked@kimfay.test',
                'role' => 'Customer Service Agent',
            ])
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}