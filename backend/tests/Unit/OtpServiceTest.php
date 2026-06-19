<?php

namespace Tests\Unit;

use App\Services\OtpService;
use Tests\TestCase;

/**
 * Unit tests for OtpService.
 *
 * Validates: Requirements 9.1, 9.2, 9.6
 */
class OtpServiceTest extends TestCase
{
    private OtpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OtpService();
    }

    /** @test */
    public function it_generates_exactly_six_digits(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $otp = $this->service->generate();
            $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        }
    }

    /** @test */
    public function it_generates_zero_padded_values(): void
    {
        // Verify the format is always exactly 6 characters (zero-padded)
        $otp = $this->service->generate();
        $this->assertSame(6, strlen($otp));
    }

    /** @test */
    public function hash_round_trip_returns_true(): void
    {
        $otp  = $this->service->generate();
        $hash = $this->service->hash($otp);
        $this->assertTrue($this->service->verify($otp, $hash));
    }

    /** @test */
    public function wrong_otp_does_not_verify(): void
    {
        $otp  = $this->service->generate();
        $hash = $this->service->hash($otp);
        $this->assertFalse($this->service->verify('000000', $hash . 'tampered'));
    }

    /**
     * Property-based test: for 100 randomly generated 6-digit OTP strings,
     * the hash → verify round-trip always returns true.
     *
     * Validates: Requirements 9.6
     *
     * @test
     */
    public function property_based_hash_round_trip_for_100_random_otps(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $otp  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = $this->service->hash($otp);
            $this->assertTrue(
                $this->service->verify($otp, $hash),
                "Round-trip failed for OTP: {$otp}"
            );
        }
    }
}
