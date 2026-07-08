<?php

namespace App\Support;

use RuntimeException;

class MailTransportValidator
{
    public static function assertConfigured(): void
    {
        $mailer = (string) config('mail.default', 'log');

        if ($mailer === 'log' || $mailer === 'array') {
            throw new RuntimeException(
                'Outbound email is not configured for production delivery. Set MAIL_MAILER=smtp and configure MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, and MAIL_FROM_ADDRESS in the server environment.',
            );
        }

        if ($mailer === 'smtp') {
            $missing = [];
            if (! config('mail.mailers.smtp.host')) {
                $missing[] = 'MAIL_HOST';
            }
            if (! config('mail.mailers.smtp.port')) {
                $missing[] = 'MAIL_PORT';
            }
            if (! config('mail.from.address') || config('mail.from.address') === 'hello@example.com') {
                $missing[] = 'MAIL_FROM_ADDRESS';
            }

            if ($missing !== []) {
                throw new RuntimeException(
                    'SMTP mail delivery is incomplete. Missing or invalid: '.implode(', ', $missing).'.',
                );
            }
        }

        if ($mailer === 'resend' && ! config('services.resend.key')) {
            throw new RuntimeException(
                'Resend mail delivery is not configured. Set RESEND_API_KEY in the server environment.',
            );
        }
    }
}