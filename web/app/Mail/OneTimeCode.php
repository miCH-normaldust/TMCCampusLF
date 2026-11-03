<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OneTimeCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'password_reset'
                ? 'TMC CampusLF password reset code'
                : 'TMC CampusLF email verification',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.one-time-code',
            with: ['purpose' => $this->purpose],
        );
    }
}
