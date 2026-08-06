<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstLoginOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_is_prompted_until_password_is_changed(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Temporary1'),
            'password_changed_at' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('must_change_password', true);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/onboarding/complete', [
            'new_password' => 'Permanent2Pass',
            'new_password_confirmation' => 'Permanent2Pass',
            'phone_number' => '+254712345678',
            'whatsapp_number' => '+254712345678',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('Permanent2Pass', $user->password));
        $this->assertSame('+254712345678', $user->phone_number);
        $this->assertSame('+254712345678', $user->whatsapp_number);

        $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')
            ->assertJsonPath('must_change_password', false);
    }

    public function test_contact_numbers_are_optional_and_current_password_cannot_be_reused(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Temporary1'),
            'password_changed_at' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/onboarding/complete', [
            'new_password' => 'Temporary1',
            'new_password_confirmation' => 'Temporary1',
        ])->assertUnprocessable()->assertJsonValidationErrors('new_password');

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/onboarding/complete', [
            'new_password' => 'Different2Password',
            'new_password_confirmation' => 'Different2Password',
        ])->assertOk();

        $this->assertNull($user->fresh()->phone_number);
        $this->assertNull($user->fresh()->whatsapp_number);
    }
}
