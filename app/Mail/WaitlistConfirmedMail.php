<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->email],
            subject: "You're on the Revat waitlist",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.waitlist-confirmed',
        );
    }
}
