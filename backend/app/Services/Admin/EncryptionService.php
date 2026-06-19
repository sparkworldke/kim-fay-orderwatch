<?php

namespace App\Services\Admin;

use Illuminate\Contracts\Encryption\DecryptException;

class EncryptionService
{
    public function encrypt(string $plaintext): string
    {
        return encrypt($plaintext);
    }

    public function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }

        try {
            return decrypt($ciphertext);
        } catch (DecryptException $exception) {
            StructuredLogger::write('error', 'encryption', 'credential_decryption_failure', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function mask(?string $value, int $visibleChars = 7): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr($value, 0, $visibleChars) . '...[masked]';
    }

    public function maskCredential(?string $value): ?string
    {
        return $this->mask($value, 4);
    }
}
