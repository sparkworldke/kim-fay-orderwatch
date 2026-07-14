<?php

namespace Tests\Feature;

use App\Mail\TeamMemberAccountMail;
use App\Models\Otp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamMemberAccountTest extends TestCase
{
    use RefreshDatabase;

    private const KNOWN_OTP = '654321';

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

    public function test_admin_can_create_team_member_and_send_welcome_email(): void
    {
        Mail::fake();
        config([
            'app.url' => 'https://api.orderwatch.test',
            'app.frontend_url' => 'https://orderwatch.test',
        ]);

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

        $createdUser = User::where('email', 'agent@kimfay.test')->first();
        $this->assertNotNull($createdUser);
        $this->assertNotNull($createdUser->email_verified_at);

        $otpRecord = Otp::where('email', 'agent@kimfay.test')->where('purpose', 'login')->first();
        $this->assertNotNull($otpRecord);
        $this->assertTrue(Hash::check(self::KNOWN_OTP, $otpRecord->otp_hash));
        $this->assertFalse($otpRecord->expires_at->isPast());
        $this->assertSame(0, $otpRecord->attempts);
        $this->assertSame(0, $otpRecord->resend_attempts);
        $this->assertDatabaseMissing('otps', ['otp_hash' => self::KNOWN_OTP]);

        Mail::assertSent(TeamMemberAccountMail::class, function (TeamMemberAccountMail $mail) use ($createdUser) {
            $html = $mail->render();

            // Extract the suggested password from the mail HTML (courier block after recommended option).
            preg_match(
                '/Option 1 \(recommended\): Sign in with password.*?font-family:\'Courier New\'[^>]*>([^<]+)</s',
                $html,
                $passwordMatch,
            );
            $suggestedPassword = trim($passwordMatch[1] ?? '');

            $passwordWorks = $suggestedPassword !== ''
                && Hash::check($suggestedPassword, (string) $createdUser->fresh()->password);

            return $mail->hasTo('agent@kimfay.test')
                && str_contains($html, 'https://orderwatch.test/app')
                && str_contains($html, 'https://orderwatch.test/auth')
                && ! str_contains($html, 'https://orderwatch.test/login')
                && str_contains($html, 'Customer Service Agent')
                && str_contains($html, self::KNOWN_OTP)
                && str_contains($html, 'Option 1 (recommended): Sign in with password')
                && str_contains($html, 'Option 2: Sign in with one-time code (OTP)')
                && str_contains($html, 'Verification dates')
                && str_contains($html, 'Account verified:')
                && str_contains($html, 'OTP valid until:')
                && str_contains($html, 'EAT')
                && $passwordWorks
                && ! str_contains($html, 'api.orderwatch.test');
        });

        $login = $this->postJson('/api/auth/otp/verify', [
            'email' => 'agent@kimfay.test',
            'otp' => self::KNOWN_OTP,
            'login_mode' => 'otp-only',
        ]);

        $login->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        $this->assertDatabaseMissing('otps', [
            'email' => 'agent@kimfay.test',
            'purpose' => 'login',
        ]);

        $this->postJson('/api/auth/otp/verify', [
            'email' => 'agent@kimfay.test',
            'otp' => self::KNOWN_OTP,
            'login_mode' => 'otp-only',
        ])->assertStatus(422);
    }

    public function test_admin_can_resend_welcome_email_with_new_password_and_otp(): void
    {
        Mail::fake();
        config([
            'app.url' => 'https://api.orderwatch.test',
            'app.frontend_url' => 'https://orderwatch.test',
        ]);

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $member = User::factory()->create([
            'name' => 'Existing Agent',
            'email' => 'existing.agent@kimfay.test',
            'role' => 'Customer Service Agent',
            'password' => Hash::make('OldPassword123'),
            'email_verified_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$member->id}/resend-welcome")
            ->assertOk()
            ->assertJsonPath('message', 'Welcome email sent successfully with a new temporary password.');

        $otpRecord = Otp::where('email', 'existing.agent@kimfay.test')->where('purpose', 'login')->first();
        $this->assertNotNull($otpRecord);
        $this->assertTrue(Hash::check(self::KNOWN_OTP, $otpRecord->otp_hash));

        Mail::assertSent(TeamMemberAccountMail::class, function (TeamMemberAccountMail $mail) use ($member) {
            $html = $mail->render();

            preg_match(
                '/Option 1 \(recommended\): Sign in with password.*?font-family:\'Courier New\'[^>]*>([^<]+)</s',
                $html,
                $passwordMatch,
            );
            $suggestedPassword = trim($passwordMatch[1] ?? '');

            return $mail->hasTo('existing.agent@kimfay.test')
                && str_contains($html, 'resent your OrderWatch sign-in details')
                && str_contains($html, self::KNOWN_OTP)
                && str_contains($html, 'Verification dates')
                && $suggestedPassword !== ''
                && Hash::check($suggestedPassword, (string) $member->fresh()->password)
                && ! Hash::check('OldPassword123', (string) $member->fresh()->password);
        });
    }

    public function test_admin_can_update_password_auto_generate_and_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $member = User::factory()->create([
            'name' => 'Password Target',
            'email' => 'password.target@kimfay.test',
            'role' => 'Customer Service Agent',
            'password' => Hash::make('OldPassword123'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$member->id}/password", [
                'auto_generate' => true,
                'email_user' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('auto_generate', true)
            ->assertJsonPath('emailed', true)
            ->assertJsonStructure(['message', 'password']);

        $generated = $response->json('password');
        $this->assertIsString($generated);
        $this->assertGreaterThanOrEqual(8, strlen($generated));
        $this->assertTrue(Hash::check($generated, (string) $member->fresh()->password));
        $this->assertFalse(Hash::check('OldPassword123', (string) $member->fresh()->password));

        Mail::assertSent(TeamMemberAccountMail::class, function (TeamMemberAccountMail $mail) use ($generated) {
            $html = $mail->render();

            return $mail->hasTo('password.target@kimfay.test')
                && str_contains($html, $generated)
                && str_contains($html, self::KNOWN_OTP);
        });
    }

    public function test_admin_can_update_password_manual_without_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $member = User::factory()->create([
            'email' => 'manual.password@kimfay.test',
            'role' => 'Customer Service Agent',
            'password' => Hash::make('OldPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$member->id}/password", [
                'auto_generate' => false,
                'password' => 'ManualPass99',
                'email_user' => false,
            ])
            ->assertOk()
            ->assertJsonPath('emailed', false)
            ->assertJsonPath('password', null);

        $this->assertTrue(Hash::check('ManualPass99', (string) $member->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_manual_password_update_requires_password_value(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $member = User::factory()->create([
            'role' => 'Customer Service Agent',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$member->id}/password", [
                'auto_generate' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        Mail::assertNothingSent();
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

    public function test_customer_service_manager_can_create_sales_consultant_with_rep_code(): void
    {
        Mail::fake();

        $manager = User::factory()->create([
            'role' => 'Customer Service Manager',
            'is_active' => true,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Shirleen Consultant',
                'email' => 'shirleen.consultant@kimfay.test',
                'role' => 'Sales Consultant',
                'rep_code' => 'p505',
            ]);

        $response->assertCreated()
            ->assertJsonPath('role', 'Sales Consultant')
            ->assertJsonPath('rep_code', 'P505');

        $this->assertDatabaseHas('users', [
            'email' => 'shirleen.consultant@kimfay.test',
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
        ]);

        Mail::assertSent(TeamMemberAccountMail::class);
    }

    public function test_customer_service_manager_cannot_create_non_consultant_roles(): void
    {
        Mail::fake();

        $manager = User::factory()->create([
            'role' => 'Customer Service Manager',
            'is_active' => true,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Blocked Agent',
                'email' => 'blocked.agent@kimfay.test',
                'role' => 'Customer Service Agent',
            ])
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
