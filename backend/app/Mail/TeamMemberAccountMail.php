<?php

namespace App\Mail;

use App\Support\FrontendUrl;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class TeamMemberAccountMail extends Mailable
{
    public function __construct(
        private readonly string $userName,
        private readonly string $email,
        private readonly string $role,
        private readonly string $invitedByName,
        private readonly string $otp,
        private readonly string $suggestedPassword,
        private readonly CarbonInterface|string|null $otpExpiresAt = null,
        private readonly CarbonInterface|string|null $accountVerifiedAt = null,
        private readonly CarbonInterface|string|null $credentialsIssuedAt = null,
        private readonly bool $isResend = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isResend
            ? 'Your Kim-Fay OrderWatch sign-in details (updated)'
            : 'Your Kim-Fay OrderWatch account is ready';

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->buildHtml());
    }

    public function attachments(): array
    {
        return [];
    }

    private function buildHtml(): string
    {
        $name = e($this->userName);
        $email = e($this->email);
        $role = e($this->role);
        $invitedBy = e($this->invitedByName);
        $otp = e($this->otp);
        $password = e($this->suggestedPassword);
        $appUrl = e(FrontendUrl::path('/app'));
        $authUrl = e(FrontendUrl::path('/auth'));

        $issuedAt = $this->formatDateTime($this->credentialsIssuedAt ?? now());
        $verifiedAt = $this->formatDateTime($this->accountVerifiedAt ?? now());
        $otpExpiresAt = $this->formatDateTime($this->otpExpiresAt ?? now()->addMinutes(15));

        $intro = $this->isResend
            ? "{$invitedBy} has resent your OrderWatch sign-in details. Your previous temporary password and OTP no longer work — use the new credentials below."
            : "{$invitedBy} has created your OrderWatch team account. You can now sign in to monitor orders, customer activity, and operational insights.";

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>Your OrderWatch account</title>
        </head>
        <body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:40px 0;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);max-width:600px;width:100%;">
                            <tr>
                                <td style="background-color:#1a1a2e;padding:28px 40px;text-align:center;">
                                    <span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">
                                        Kim-Fay OrderWatch
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:40px 40px 24px;">
                                    <p style="margin:0 0 16px;font-size:16px;color:#374151;line-height:1.6;">
                                        Hi {$name},
                                    </p>
                                    <p style="margin:0 0 16px;font-size:16px;color:#374151;line-height:1.6;">
                                        {$intro}
                                    </p>
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                                        <tr>
                                            <td style="padding:16px 20px;font-size:14px;color:#374151;line-height:1.7;">
                                                <strong>Email:</strong> {$email}<br />
                                                <strong>Role:</strong> {$role}<br />
                                                <strong>Account verified:</strong> {$verifiedAt}<br />
                                                <strong>Credentials issued:</strong> {$issuedAt}
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0 0 14px;font-size:15px;color:#374151;line-height:1.6;">
                                        Use these secure options to access your account:
                                    </p>

                                    <!-- Primary: password -->
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                                        <tr>
                                            <td style="padding:18px 20px;font-size:14px;color:#374151;line-height:1.7;">
                                                <strong>Option 1 (recommended): Sign in with password</strong><br />
                                                1. Open the sign-in page below.<br />
                                                2. Keep the <strong>Password</strong> tab selected.<br />
                                                3. Enter your email and this suggested temporary password:
                                            </td>
                                        </tr>
                                        <tr>
                                            <td align="center" style="padding:0 20px 10px;">
                                                <span style="display:inline-block;background-color:#ecfdf5;border:2px solid #16a34a;border-radius:8px;padding:14px 24px;font-size:22px;font-weight:700;letter-spacing:2px;color:#14532d;font-family:'Courier New',Courier,monospace;">
                                                    {$password}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:0 20px 18px;font-size:12px;color:#6b7280;line-height:1.6;text-align:center;">
                                                Change this password after your first sign-in from <strong>Profile → Update Password</strong>.
                                            </td>
                                        </tr>
                                    </table>

                                    <!-- Secondary: OTP -->
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                        <tr>
                                            <td style="padding:18px 20px;font-size:14px;color:#374151;line-height:1.7;">
                                                <strong>Option 2: Sign in with one-time code (OTP)</strong><br />
                                                1. Open the sign-in page and choose <strong>Login via OTP</strong>.<br />
                                                2. Enter your email, then this verification code.<br />
                                                3. Code expires at <strong>{$otpExpiresAt}</strong> (15 minutes) and can be used only once.
                                            </td>
                                        </tr>
                                        <tr>
                                            <td align="center" style="padding:0 20px 18px;">
                                                <span style="display:inline-block;background-color:#eef2ff;border:2px solid #4f6ef7;border-radius:8px;padding:14px 28px;font-size:34px;font-weight:700;letter-spacing:8px;color:#1a1a2e;font-family:'Courier New',Courier,monospace;">
                                                    {$otp}
                                                </span>
                                            </td>
                                        </tr>
                                    </table>

                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;">
                                        <tr>
                                            <td style="padding:14px 18px;font-size:13px;color:#92400e;line-height:1.6;">
                                                <strong>Verification dates</strong><br />
                                                Account verified: {$verifiedAt}<br />
                                                Credentials issued: {$issuedAt}<br />
                                                OTP valid until: {$otpExpiresAt}
                                            </td>
                                        </tr>
                                    </table>

                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding:8px 0 12px;">
                                                <a href="{$authUrl}" style="display:inline-block;background:#4f6ef7;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 28px;border-radius:8px;">
                                                    Open sign-in page
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;text-align:center;">
                                        <a href="{$appUrl}" style="color:#4f6ef7;text-decoration:none;">{$appUrl}</a>
                                        <span style="color:#d1d5db;"> &bull; </span>
                                        <a href="{$authUrl}" style="color:#4f6ef7;text-decoration:none;">Sign-in page</a>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:0 40px;">
                                    <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;" />
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:24px 40px 36px;text-align:center;">
                                    <p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;">
                                        If you were not expecting this account, please contact your OrderWatch administrator.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }

    private function formatDateTime(CarbonInterface|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $carbon = $value instanceof CarbonInterface
            ? Carbon::instance($value)->timezone(config('app.timezone', 'Africa/Nairobi'))
            : Carbon::parse($value)->timezone(config('app.timezone', 'Africa/Nairobi'));

        return $carbon->format('d M Y, H:i').' EAT';
    }
}
