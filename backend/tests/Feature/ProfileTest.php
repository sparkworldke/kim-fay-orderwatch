<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\AuditLog;
use App\Models\Otp;
use App\Models\PasswordChangeLog;
use App\Models\SignInLog;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for profile and sign-in log endpoints.
 *
 * Validates: Requirements 9.5
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN_OTP = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        $knownOtp = self::KNOWN_OTP;
        $this->app->instance(OtpService::class, new class ($knownOtp) extends OtpService {
            public function __construct(private readonly string $knownOtp) {}

            public function generate(): string
            {
                return $this->knownOtp;
            }
        });
    }

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

    /** @test */
    public function test_password_update_otp_resend_limit_is_enforced(): void
    {
        Mail::fake();
        $user = $this->makeUser(['email' => 'secure@example.com']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/password/otp')
            ->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/profile/password/otp')
                ->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/password/otp')
            ->assertStatus(429)
            ->assertJsonFragment(['code' => 'too_many_resends']);

        $otpRecord = Otp::where('email', $user->email)
            ->where('purpose', 'password-update')
            ->first();

        $this->assertNotNull($otpRecord);
        $this->assertSame(3, $otpRecord->resend_attempts);
        Mail::assertSent(OtpMail::class, 4);
    }

    /** @test */
    public function test_password_update_requires_valid_otp_but_not_current_password(): void
    {
        Mail::fake();
        $user = $this->makeUser([
            'email' => 'password-user@example.com',
            'password' => bcrypt('old-password'),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/password/otp')
            ->assertOk();

        $otpRecord = Otp::where('email', $user->email)
            ->where('purpose', 'password-update')
            ->first();

        $this->assertNotNull($otpRecord);
        $this->assertTrue(Hash::check(self::KNOWN_OTP, $otpRecord->otp_hash));
        $this->assertDatabaseMissing('otps', ['otp_hash' => self::KNOWN_OTP]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/profile/password/otp/verify', ['otp' => self::KNOWN_OTP])
            ->assertOk()
            ->assertJsonFragment(['message' => 'OTP verified successfully.']);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile/password', [
                'otp' => self::KNOWN_OTP,
                'new_password' => 'new-secure-password',
                'new_password_confirmation' => 'new-secure-password',
            ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email', 'role']]);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
        $this->assertDatabaseMissing('otps', [
            'email' => $user->email,
            'purpose' => 'password-update',
        ]);
        $this->assertDatabaseHas('password_change_logs', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action_type' => 'password_updated',
            'resource_type' => 'user',
            'resource_id' => (string) $user->id,
        ]);
        $this->assertSame(1, PasswordChangeLog::where('user_id', $user->id)->count());
        $this->assertGreaterThanOrEqual(1, AuditLog::where('actor_user_id', $user->id)->count());
    }

    /** @test */
    public function test_password_update_rejects_invalid_and_expired_otps(): void
    {
        Mail::fake();
        $user = $this->makeUser(['email' => 'expired@example.com']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile/password', [
                'otp' => '000000',
                'new_password' => 'new-secure-password',
                'new_password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Invalid or expired verification code.']);

        Otp::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'purpose' => 'password-update',
            'otp_hash' => bcrypt(self::KNOWN_OTP),
            'expires_at' => now()->subMinute(),
            'attempts' => 0,
            'resend_attempts' => 0,
            'resend_window_start' => now()->subMinutes(16),
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/profile/password', [
                'otp' => self::KNOWN_OTP,
                'new_password' => 'new-secure-password',
                'new_password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Verification code has expired. Please request a new one.']);
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
