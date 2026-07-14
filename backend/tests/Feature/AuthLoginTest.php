<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_invalid_credentials_message_for_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'rep@kimfay.test',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'rep@kimfay.test',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid credentials. Please check your email and password.');
    }

    public function test_login_returns_invalid_credentials_message_for_unknown_email(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'missing@kimfay.test',
            'password' => 'anything',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid credentials. Please check your email and password.');
    }

    public function test_login_returns_inactive_message_for_disabled_account(): void
    {
        User::factory()->create([
            'email' => 'inactive@kimfay.test',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'inactive@kimfay.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account is not active. Please contact an administrator.');
    }

    public function test_login_succeeds_for_active_user_with_correct_password(): void
    {
        $user = User::factory()->create([
            'email' => 'ok@kimfay.test',
            'password' => Hash::make('password'),
            'is_active' => true,
            'name' => 'Active User',
            'role' => 'Administrator',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ok@kimfay.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['token', 'user', 'capabilities']);
    }
}
