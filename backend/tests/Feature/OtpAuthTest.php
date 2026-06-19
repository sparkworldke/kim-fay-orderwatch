<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Feature tests for OTP authentication flow.
 *
 * Validates: Requirements 9.3, 9.4, 9.7
 */
class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    /** Known OTP value injected via mock for deterministic tests. */
    private const KNOWN_OTP = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a deterministic OtpService so tests can use the known OTP value.
        $knownOtp = self::KNOWN_OTP;
        $this->app->instance(OtpService::class, new class ($knownOtp) extends OtpService {
            public function __construct(private readonly string $knownOtp) {}

            public function generate(): string
            {
                return $this->knownOtp;
            }
        });
    }

    protected function tearDown(): void
    {
        // Clear rate limiter buckets so tests are isolated.
        RateLimiter::clear('otp-request|127.0.0.1:test@example.com');
        RateLimiter::clear('otp-verify|127.0.0.1:test@example.com');

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email'    => 'test@example.com',
            'password' => bcrypt('secret123'),
            'role'     => 'User',
        ], $overrides));
    }

    private function requestOtp(string $email = 'test@example.com'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/otp/request', ['email' => $email]);
    }

    private function verifyOtp(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/otp/verify', $payload);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function test_otp_only_happy_path(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->requestOtp($user->email)->assertStatus(200);

        Mail::assertSent(OtpMail::class);

        $response = $this->verifyOtp([
            'email'      => $user->email,
            'otp'        => self::KNOWN_OTP,
            'login_mode' => 'otp-only',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ]);
    }

    /** @test */
    public function test_email_check_reports_registered_active_user(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/email/check', ['email' => strtoupper($user->email)])
            ->assertStatus(200)
            ->assertJson([
                'exists' => true,
                'eligible' => true,
                'status' => 'registered',
            ]);
    }

    /** @test */
    public function test_email_check_distinguishes_unregistered_email(): void
    {
        $this->postJson('/api/auth/email/check', ['email' => 'missing@example.com'])
            ->assertStatus(200)
            ->assertJson([
                'exists' => false,
                'eligible' => false,
                'status' => 'not_registered',
                'message' => 'This email is not registered in OrderWatch.',
            ]);
    }

    /** @test */
    public function test_unregistered_email_does_not_send_or_create_otp(): void
    {
        Mail::fake();

        $this->requestOtp('missing@example.com')
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This email is not registered in OrderWatch.',
                'code' => 'email_not_registered',
            ]);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('otps', 0);
    }

    /** @test */
    public function test_inactive_user_does_not_send_or_create_otp(): void
    {
        Mail::fake();

        $user = $this->makeUser(['is_active' => false]);

        $this->requestOtp($user->email)
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This account is not active. Contact your administrator.',
                'code' => 'account_inactive',
            ]);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('otps', 0);
    }

    /** @test */
    public function test_unverified_user_does_not_send_or_create_otp(): void
    {
        Mail::fake();

        $user = $this->makeUser(['email_verified_at' => null]);

        $this->requestOtp($user->email)
            ->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This account is not active. Contact your administrator.',
                'code' => 'account_inactive',
            ]);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('otps', 0);
    }

    /** @test */
    public function test_mail_delivery_failure_rolls_back_created_otp(): void
    {
        $user = $this->makeUser();

        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andThrow(new \RuntimeException('SMTP transport failed'));

        $this->requestOtp($user->email)
            ->assertStatus(503)
            ->assertJsonFragment([
                'message' => 'Failed to send verification email. Please try again.',
                'code' => 'otp_mail_failed',
            ]);

        $this->assertDatabaseCount('otps', 0);
    }

    /** @test */
    public function test_otp_and_password_happy_path(): void
    {
        Mail::fake();

        $user = $this->makeUser(['password' => bcrypt('correct-password')]);

        $this->requestOtp($user->email)->assertStatus(200);

        Mail::assertSent(OtpMail::class);

        $response = $this->verifyOtp([
            'email'      => $user->email,
            'otp'        => self::KNOWN_OTP,
            'login_mode' => 'otp-and-password',
            'password'   => 'correct-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ]);
    }

    /** @test */
    public function test_wrong_otp_returns_422(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->requestOtp($user->email)->assertStatus(200);

        $response = $this->verifyOtp([
            'email'      => $user->email,
            'otp'        => '000000',
            'login_mode' => 'otp-only',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Invalid OTP.']);
    }

    /** @test */
    public function test_expired_otp_returns_422(): void
    {
        $user = $this->makeUser();

        // Create an OTP record that is already expired
        Otp::create([
            'user_id'    => $user->id,
            'email'      => $user->email,
            'otp_hash'   => bcrypt(self::KNOWN_OTP),
            'expires_at' => now()->subMinutes(1),
            'attempts'   => 0,
        ]);

        $response = $this->verifyOtp([
            'email'      => $user->email,
            'otp'        => self::KNOWN_OTP,
            'login_mode' => 'otp-only',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'OTP has expired. Please request a new one.']);
    }

    /** @test */
    public function test_five_wrong_attempts_locks_out(): void
    {
        Mail::fake();

        $user = $this->makeUser();

        $this->requestOtp($user->email)->assertStatus(200);

        $wrongPayload = [
            'email'      => $user->email,
            'otp'        => '999999',
            'login_mode' => 'otp-only',
        ];

        // Four wrong attempts — each should return 422
        for ($i = 0; $i < 4; $i++) {
            $this->verifyOtp($wrongPayload)->assertStatus(422);
        }

        // Fifth wrong attempt should trigger lockout — 429
        $this->verifyOtp($wrongPayload)->assertStatus(429);
    }

    /** @test */
    public function test_wrong_password_in_otp_and_password_mode_returns_422_without_consuming_attempt(): void
    {
        Mail::fake();

        $user = $this->makeUser(['password' => bcrypt('correct-password')]);

        $this->requestOtp($user->email)->assertStatus(200);

        $attemptsBefore = Otp::where('email', $user->email)->value('attempts');

        $response = $this->verifyOtp([
            'email'      => $user->email,
            'otp'        => self::KNOWN_OTP,
            'login_mode' => 'otp-and-password',
            'password'   => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Invalid credentials.']);

        // Attempt count must not have changed
        $attemptsAfter = Otp::where('email', $user->email)->value('attempts');
        $this->assertSame($attemptsBefore, $attemptsAfter);
    }

    /** @test */
    public function test_protected_route_without_token_returns_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    /** @test */
    public function test_rate_limit_on_otp_request(): void
    {
        $user = $this->makeUser();

        // Clear the rate limit bucket for this IP + email combination first
        RateLimiter::clear('otp-request' . $this->app->make(\Illuminate\Http\Request::class)->ip() . ':' . $user->email);

        Mail::fake();

        // Send 5 valid requests — all should succeed
        for ($i = 0; $i < 5; $i++) {
            $this->requestOtp($user->email)->assertStatus(200);
        }

        // 6th request should be rate-limited
        $this->requestOtp($user->email)->assertStatus(429);
    }
}
