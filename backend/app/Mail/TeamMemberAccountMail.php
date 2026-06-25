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
                                    <p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;">
                                        Sign in with your work email. OrderWatch will send you a one-time verification code each time you log in.
                                    </p>
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding:8px 0 12px;">
                                                <a href="{$appUrl}" style="display:inline-block;background:#4f6ef7;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 28px;border-radius:8px;">
                                                    Open OrderWatch
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