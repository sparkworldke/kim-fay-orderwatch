<?php

namespace App\Mail;

use App\Support\FrontendUrl;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpMail extends Mailable
{

    public function __construct(
        private readonly string $otp,
        private readonly string $userName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            subject: 'Your Kim-Fay OrderWatch verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function buildHtml(): string
    {
        $name = e($this->userName);
        $code = e($this->otp);
        $appUrl = e(FrontendUrl::path('/app'));
        $authUrl = e(FrontendUrl::path('/auth'));

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>Your verification code</title>
        </head>
        <body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:40px 0;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);max-width:600px;width:100%;">

                            <!-- Header -->
                            <tr>
                                <td style="background-color:#1a1a2e;padding:28px 40px;text-align:center;">
                                    <span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:0.5px;">
                                        Kim-Fay OrderWatch
                                    </span>
                                </td>
                            </tr>

                            <!-- Body -->
                            <tr>
                                <td style="padding:40px 40px 24px;">
                                    <p style="margin:0 0 16px;font-size:16px;color:#374151;line-height:1.6;">
                                        Hi {$name},
                                    </p>
                                    <p style="margin:0 0 24px;font-size:16px;color:#374151;line-height:1.6;">
                                        Your verification code is:
                                    </p>

                                    <!-- OTP display -->
                                    <table width="100%" cellpadding="0" cellspacing="0">
                                        <tr>
                                            <td align="center" style="padding:16px 0;">
                                                <span style="display:inline-block;background-color:#f0f4ff;border:2px solid #4f6ef7;border-radius:8px;padding:18px 36px;font-size:42px;font-weight:700;letter-spacing:10px;color:#1a1a2e;font-family:'Courier New',Courier,monospace;">
                                                    {$code}
                                                </span>
                                            </td>
                                        </tr>
                                    </table>

                                    <p style="margin:24px 0 0;font-size:14px;color:#6b7280;line-height:1.6;text-align:center;">
                                        This code expires in <strong>15 minutes</strong>. Do not share it with anyone.
                                    </p>
                                </td>
                            </tr>

                            <!-- Divider -->
                            <tr>
                                <td style="padding:0 40px;">
                                    <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;" />
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style="padding:24px 40px 36px;text-align:center;">
                                    <p style="margin:0 0 12px;font-size:13px;color:#9ca3af;line-height:1.6;">
                                        If you did not request this, please ignore this email.
                                    </p>
                                    <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">
                                        <a href="{$appUrl}" style="color:#4f6ef7;text-decoration:none;">Open OrderWatch</a>
                                        <span style="color:#d1d5db;"> &bull; </span>
                                        <a href="{$authUrl}" style="color:#4f6ef7;text-decoration:none;">Sign-in page</a>
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
