<?php

namespace App\Services;

use Illuminate\Support\Facades\Hash;

class OtpService
{
    /**
     * Generate a cryptographically random 6-digit OTP string (zero-padded).
     */
    public function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Return a bcrypt hash of the given OTP plaintext.
     */
    public function hash(string $otp): string
    {
        return Hash::make($otp);
    }

    /**
     * Verify an OTP plaintext against a stored bcrypt hash.
     */
    public function verify(string $otp, string $hash): bool
    {
        return Hash::check($otp, $hash);
    }
}
