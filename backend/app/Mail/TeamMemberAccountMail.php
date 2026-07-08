<?php

namespace App\Mail;

use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TeamMemberAccountMail extends Mailable
{
    public function __construct(
        private readonly string $userName,
        private readonly string $email,
        private readonly string $role,
        private readonly string $invitedByName,
        private readonly string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            subject: 'Your Kim-Fay OrderWatch account is ready',
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
        $appUrl = e(FrontendUrl::path('/app'));
        $authUrl = e(FrontendUrl::path('/auth'));

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
                                        {$invitedBy} has created your OrderWatch team account. You can now sign in to monitor orders, customer activity, and operational insights.
                                    </p>
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                                        <tr>
                                            <td style="padding:16px 20px;font-size:14px;color:#374151;line-height:1.7;">
                                                <strong>Email:</strong> {$email}<br />
                                                <strong>Role:</strong> {$role}
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:0 0 14px;font-size:15px;color:#374151;line-height:1.6;">
                                        You have two secure ways to access your account:
                                    </p>
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                                        <tr>
                                            <td style="padding:18px 20px;font-size:14px;color:#374151;line-height:1.7;">
                                                <strong>Option 1: Sign in now with this one-time password</strong><br />
                                                1. Open the sign-in page below.<br />
                                                2. Choose <strong>Login via OTP</strong> and enter your email address.<br />
                                                3. Enter this code when prompted. It expires in <strong>15 minutes</strong> and can be used only once.
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
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                                        <tr>
                                            <td style="padding:18px 20px;font-size:14px;color:#374151;line-height:1.7;">
                                                <strong>Option 2: Set up a permanent password</strong><br />
                                                1. First sign in using the OTP above.<br />
                                                2. Open <strong>Profile</strong> from your account menu.<br />
                                                3. Select <strong>Update Password</strong>. OrderWatch will email a new verification code before allowing the password change.
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
}
