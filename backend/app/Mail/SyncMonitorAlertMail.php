<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SyncMonitorAlertMail extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address(
                config('mail.from.address'),
                config('mail.from.name'),
            ),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->body)),
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

