<?php

namespace Tests\Feature;

use App\Models\SignInLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for profile and sign-in log endpoints.
 *
 * Validates: Requirements 9.5
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'User',
        ], $overrides));
    }

    private function makeLog(User $user, array $overrides = []): SignInLog
    {
        return SignInLog::create(array_merge([
            'user_id'    => $user->id,
            'email_hash' => hash('sha256', $user->email),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'login_mode' => 'otp-only',
            'status'     => 'success',
            'created_at' => now(),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // GET /api/profile
    // -------------------------------------------------------------------------

    /** @test */
    public function test_get_profile_returns_correct_fields(): void
    {
        $user = $this->makeUser(['phone_number' => '+254712345678']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile');

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'name', 'email', 'role', 'phone_number', 'updated_at'])
            ->assertJsonFragment([
                'id'           => $user->id,
                'email'        => $user->email,
                'phone_number' => '+254712345678',
            ]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/profile — phone_number
    // -------------------------------------------------------------------------

    /** @test */
    public function test_patch_profile_with_valid_e164_phone(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['phone_number' => '+254712345678']);

        $response->assertStatus(200)
            ->assertJsonFragment(['phone_number' => '+254712345678']);

        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'phone_number' => '+254712345678',
        ]);
    }

    /** @test */
    public function test_patch_profile_with_invalid_phone_returns_422(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['phone_number' => '07123456789']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/profile — name
    // -------------------------------------------------------------------------

    /** @test */
    public function test_patch_profile_with_valid_name(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['name' => 'Jane Doe']);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Jane Doe']);

        $this->assertDatabaseHas('users', [
            'id'   => $user->id,
            'name' => 'Jane Doe',
        ]);
    }

    /** @test */
    public function test_patch_profile_with_short_name_returns_422(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile', ['name' => 'J']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // -------------------------------------------------------------------------
    // GET /api/profile/sign-in-logs
    // -------------------------------------------------------------------------

    /** @test */
    public function test_sign_in_logs_returns_only_own_logs(): void
    {
        $userA = $this->makeUser(['email' => 'a@example.com']);
        $userB = $this->makeUser(['email' => 'b@example.com']);

        $this->makeLog($userA);
        $this->makeLog($userA);
        $this->makeLog($userB);

        $response = $this->actingAs($userA, 'sanctum')
            ->getJson('/api/profile/sign-in-logs');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    // -------------------------------------------------------------------------
    // GET /api/admin/users/{user}/sign-in-logs
    // -------------------------------------------------------------------------

    /** @test */
    public function test_non_admin_cannot_access_another_users_logs_via_admin_route(): void
    {
        $regularUser = $this->makeUser(['role' => 'User', 'email' => 'regular@example.com']);
        $targetUser  = $this->makeUser(['role' => 'User', 'email' => 'target@example.com']);

        $this->makeLog($targetUser);

        $response = $this->actingAs($regularUser, 'sanctum')
            ->getJson("/api/admin/users/{$targetUser->id}/sign-in-logs");

        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_can_access_any_users_logs(): void
    {
        $admin      = $this->makeUser(['role' => 'Administrator', 'email' => 'admin@example.com']);
        $targetUser = $this->makeUser(['role' => 'User',          'email' => 'target@example.com']);

        $this->makeLog($targetUser);
        $this->makeLog($targetUser);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/users/{$targetUser->id}/sign-in-logs");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }
}
