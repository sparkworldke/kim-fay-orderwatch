<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Deliver one-time codes over WhatsApp.
 *
 * Drivers:
 * - log: writes the message (local/testing; always "succeeds")
 * - meta: Meta Cloud API (requires WHATSAPP_TOKEN + WHATSAPP_PHONE_NUMBER_ID)
 */
class WhatsAppOtpService
{
    public function isConfigured(): bool
    {
        $driver = $this->driver();

        if ($driver === 'log') {
            return true;
        }

        return $driver === 'meta'
            && filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    public function driver(): string
    {
        return strtolower((string) config('services.whatsapp.driver', 'log'));
    }

    /**
     * @throws RuntimeException when WhatsApp cannot be delivered
     */
    public function sendOtp(string $e164Phone, string $otp, string $purpose = 'password-update'): void
    {
        $to = $this->normalizePhone($e164Phone);
        if ($to === null) {
            throw new RuntimeException('WhatsApp number is invalid. Use international format, e.g. +254712345678.');
        }

        $body = $this->messageBody($otp, $purpose);

        if ($this->driver() === 'log') {
            Log::info('whatsapp_otp_sent', [
                'driver' => 'log',
                'to_hash' => hash('sha256', $to),
                'purpose' => $purpose,
                // Never log the plaintext OTP in production drivers; log driver is for local only.
                'otp_preview' => app()->environment('production') ? null : $otp,
            ]);

            return;
        }

        if ($this->driver() !== 'meta') {
            throw new RuntimeException('WhatsApp delivery is not configured.');
        }

        $token = (string) config('services.whatsapp.token');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');
        $apiVersion = (string) config('services.whatsapp.api_version', 'v21.0');
        $template = config('services.whatsapp.otp_template');

        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

        $payload = is_string($template) && $template !== ''
            ? [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) config('services.whatsapp.otp_template_language', 'en')],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $otp],
                            ],
                        ],
                        [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => '0',
                            'parameters' => [
                                ['type' => 'text', 'text' => $otp],
                            ],
                        ],
                    ],
                ],
            ]
            : [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $body],
            ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->post($url, $payload);

        if (! $response->successful()) {
            Log::error('whatsapp_otp_failed', [
                'status' => $response->status(),
                'to_hash' => hash('sha256', $to),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new RuntimeException('Failed to send verification code via WhatsApp.');
        }

        Log::info('whatsapp_otp_sent', [
            'driver' => 'meta',
            'to_hash' => hash('sha256', $to),
            'purpose' => $purpose,
            'message_id' => data_get($response->json(), 'messages.0.id'),
        ]);
    }

    private function messageBody(string $otp, string $purpose): string
    {
        $label = match ($purpose) {
            'password-update' => 'password update',
            'login' => 'sign-in',
            default => 'verification',
        };

        return "Kim-Fay Sight: Your {$label} code is {$otp}. It expires in 15 minutes. Do not share this code.";
    }

    /** Digits only, no leading +. Returns null if too short. */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }
}
